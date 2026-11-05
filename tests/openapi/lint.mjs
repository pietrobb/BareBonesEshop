// Lint docs/openapi.yaml (CI): a strict YAML parse, then the mistakes a lenient parser accepts silently. Review round 16:
// an unquoted description with a comma inside a flow mapping ({ type: string, description: a, b }) ended the value at
// the comma and made "b" a key with no value, so client generators got a wrong schema while the file still parsed.
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { parseDocument, isMap, isScalar } from 'yaml';

// Keys that may hold null in an OpenAPI 3.1 document (a null default, example or const).
const NULLABLE_KEYS = new Set(['default', 'example', 'const']);
// JSON Schema 2020-12 and OpenAPI 3.1 keywords a schema object may carry (x- extensions allowed).
const SCHEMA_KEYWORDS = new Set([
  'type', 'format', 'description', 'title', 'default', 'example', 'examples', 'enum', 'const', 'nullable', 'deprecated',
  'readOnly', 'writeOnly', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'minLength',
  'maxLength', 'pattern', 'minItems', 'maxItems', 'uniqueItems', 'items', 'prefixItems', 'contains', 'minProperties',
  'maxProperties', 'required', 'properties', 'additionalProperties', 'patternProperties', 'propertyNames',
  'unevaluatedProperties', 'allOf', 'anyOf', 'oneOf', 'not', 'if', 'then', 'else', '$ref', 'discriminator', 'xml',
  'externalDocs', 'contentMediaType', 'contentEncoding', '$comment',
]);

export function lintOpenApi(text) {
  const errors = [];
  const doc = parseDocument(text, { uniqueKeys: true, strict: true, prettyErrors: false });
  for (const error of [...doc.errors, ...doc.warnings]) errors.push(`YAML: ${error.message.split('\n')[0]}`);
  if (doc.errors.length > 0) return errors;

  const where = (node) => (node && node.range ? ` (line ${lineOf(text, node.range[0])})` : '');
  const walk = (node, path, inSchema) => {
    if (isMap(node)) {
      const keys = node.items.map((pair) => String(isScalar(pair.key) ? pair.key.value : pair.key));
      const isSchema = inSchema === true && keys.some((key) => ['type', '$ref', 'properties', 'items', 'allOf', 'anyOf', 'oneOf', 'enum'].includes(key));
      for (const pair of node.items) {
        const key = String(isScalar(pair.key) ? pair.key.value : pair.key);
        const value = pair.value;
        if ((value === null || (isScalar(value) && value.value === null)) && !NULLABLE_KEYS.has(key)) {
          errors.push(`${path.join('.')}: key "${key}" has no value${where(pair.key)} — an unquoted comma in a flow mapping?`);
        }
        if (isSchema && !SCHEMA_KEYWORDS.has(key) && !key.startsWith('x-')) {
          errors.push(`${path.join('.')}: "${key}" is not a schema keyword${where(pair.key)}`);
        }
        // Example and literal values are data, not document structure.
        if (['example', 'examples', 'default', 'const', 'enum'].includes(key)) continue;
        // Below these keys the children are named entries (property names, schema names), not keywords.
        // A security scheme has a "type" too, but is no schema.
        if (key === 'securitySchemes') { walk(value, [...path, key], false); continue; }
        const named = ['properties', 'schemas', 'patternProperties', 'responses', 'paths', 'parameters', 'headers', 'content'].includes(key);
        walk(value, [...path, key], named ? 'named' : (inSchema === 'named' || isSchema || ['schema', 'items', 'additionalProperties', 'not'].includes(key)));
      }
    } else if (node && Array.isArray(node.items)) {
      node.items.forEach((item, index) => walk(item, [...path, String(index)], inSchema));
    }
  };
  walk(doc.contents, [], false);
  return errors;
}

function lineOf(text, offset) {
  return text.slice(0, offset).split('\n').length;
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const file = process.argv[2] || new URL('../../docs/openapi.yaml', import.meta.url);
  const errors = lintOpenApi(readFileSync(file, 'utf8'));
  for (const error of errors) console.error(error);
  console.log(errors.length === 0 ? 'OpenAPI lint passed' : `OpenAPI lint FAILED (${errors.length})`);
  process.exit(errors.length === 0 ? 0 : 1);
}
