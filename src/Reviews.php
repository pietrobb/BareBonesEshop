<?php
/**
 * BareBones Shop — Product Reviews
 *
 * Customer/guest product ratings (1-5 stars) with a moderation workflow.
 * New reviews land as 'pending' and only become publicly visible once an admin
 * moves them to 'approved'. Guest reviews are supported (customer_id nullable);
 * author_name is the public display name.
 *
 * Storefront display uses approved() + aggregate(); admin moderation uses
 * pending() + setStatus().
 */
class Reviews {

    private const MIN_RATING = 1;
    private const MAX_RATING = 5;
    private const MAX_TITLE  = 150;
    private const MAX_BODY   = 2000;
    private const MIN_BODY   = 3;

    public const STATUSES = ['pending', 'approved', 'rejected'];

    /**
     * Submit a new review. Always stored as 'pending'.
     * Returns [success, id|null, error|null]; errors are stable strings for
     * form display (invalid_rating, body_too_short, body_too_long, author_required).
     */
    public static function submit(int $productId, array $data): array {
        if ($productId <= 0) {
            return [false, null, 'invalid_product'];
        }
        $rating = (int)($data['rating'] ?? 0);
        if ($rating < self::MIN_RATING || $rating > self::MAX_RATING) {
            return [false, null, 'invalid_rating'];
        }
        $body = trim((string)($data['body'] ?? ''));
        if (mb_strlen($body) < self::MIN_BODY) {
            return [false, null, 'body_too_short'];
        }
        if (mb_strlen($body) > self::MAX_BODY) {
            return [false, null, 'body_too_long'];
        }
        $title = trim((string)($data['title'] ?? ''));
        if (mb_strlen($title) > self::MAX_TITLE) {
            $title = mb_substr($title, 0, self::MAX_TITLE);
        }
        $customerId = isset($data['customer_id']) ? (int)$data['customer_id'] : 0;
        $customerId = $customerId > 0 ? $customerId : null;
        $authorName = trim((string)($data['author_name'] ?? ''));
        if ($authorName === '') {
            return [false, null, 'author_required'];
        }

        $db = Db::get();
        $ins = $db->prepare(
            "INSERT INTO product_reviews
                (product_id, customer_id, author_name, rating, title, body, status)
             VALUES (?, ?, ?, ?, ?, ?, 'pending')"
        );
        $ins->execute([$productId, $customerId, $authorName, $rating, $title, $body]);
        return [true, (int)$db->lastInsertId(), null];
    }

    /**
     * Approved reviews for a product, newest first.
     */
    public static function approved(int $productId, int $limit = 50, int $offset = 0): array {
        $stmt = Db::get()->prepare(
            "SELECT id, product_id, customer_id, author_name, rating, title, body, created_at
             FROM product_reviews
             WHERE product_id = ? AND status = 'approved'
             ORDER BY id DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$productId, $limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Pending reviews awaiting moderation, oldest first (FIFO queue).
     */
    public static function pending(int $limit = 100, int $offset = 0): array {
        $stmt = Db::get()->prepare(
            "SELECT r.id, r.product_id, r.customer_id, r.author_name, r.rating, r.title, r.body, r.created_at
             FROM product_reviews r
             JOIN products p ON p.id = r.product_id AND p.store_id = ?
             WHERE r.status = 'pending'
             ORDER BY r.id ASC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([StoreContext::current()->catalogId(), $limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Aggregate rating for a product over APPROVED reviews only.
     * Returns ['avg' => float (1 decimal), 'count' => int]. count=0 → avg=0.0.
     */
    public static function aggregate(int $productId): array {
        $stmt = Db::get()->prepare(
            "SELECT COUNT(*) AS c, AVG(rating) AS a
             FROM product_reviews
             WHERE product_id = ? AND status = 'approved'"
        );
        $stmt->execute([$productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['c' => 0, 'a' => null];
        $count = (int)$row['c'];
        $avg = $count > 0 ? round((float)$row['a'], 1) : 0.0;
        return ['avg' => $avg, 'count' => $count];
    }

    public static function byId(int $id): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM product_reviews WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Moderation: move a review to approved/rejected/pending.
     * Returns false for unknown status or non-existent review.
     */
    public static function setStatus(int $id, string $status): bool {
        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }
        $stmt = Db::get()->prepare(
            "UPDATE product_reviews
             SET status = ?, updated_at = " . (Db::isSqlite() ? "datetime('now')" : "NOW()") . "
             WHERE id = ? AND product_id IN (SELECT id FROM products WHERE store_id = ?)"
        );
        $stmt->execute([$status, $id, StoreContext::current()->catalogId()]);
        return $stmt->rowCount() > 0;
    }
}
