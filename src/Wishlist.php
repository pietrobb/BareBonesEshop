<?php
/**
 * BareBones Shop — Wishlist / Saved Products
 *
 * Per-owner list of saved products. The owner is either a logged-in customer
 * ("c:<id>") or an anonymous session ("s:<session_id>"). All public methods
 * accept an optional explicit $ownerKey (used by tests); when omitted the
 * owner is resolved from the current session via ownerKey().
 *
 * On login the guest list can be folded into the customer list via merge().
 */
class Wishlist {

    /**
     * Resolve the owner key for the current request: prefer the logged-in
     * customer, else fall back to the session id (anonymous wishlist).
     */
    public static function ownerKey(): string {
        Security::sessionStart();
        $cid = (int)($_SESSION['customer_id'] ?? 0);
        if ($cid > 0) {
            return 'c:' . $cid;
        }
        $sid = session_id();
        return 's:' . ($sid !== '' ? $sid : 'anon');
    }

    private static function resolve(?string $ownerKey): string {
        return $ownerKey !== null && $ownerKey !== '' ? $ownerKey : self::ownerKey();
    }

    private static function customerIdFor(string $ownerKey): ?int {
        return strncmp($ownerKey, 'c:', 2) === 0 ? (int)substr($ownerKey, 2) : null;
    }

    /**
     * Add a product to the owner's wishlist (idempotent — duplicates ignored).
     * Returns true if a new row was inserted, false if it was already present
     * or the product_id is invalid.
     */
    public static function add(int $productId, ?string $ownerKey = null): bool {
        if ($productId <= 0) return false;
        $owner = self::resolve($ownerKey);
        $db = Db::get();
        $sql = Db::isSqlite()
            ? "INSERT OR IGNORE INTO wishlist_items (owner_key, customer_id, product_id) VALUES (?, ?, ?)"
            : "INSERT IGNORE INTO wishlist_items (owner_key, customer_id, product_id) VALUES (?, ?, ?)";
        $stmt = $db->prepare($sql);
        $stmt->execute([$owner, self::customerIdFor($owner), $productId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Remove a product from the owner's wishlist.
     * Returns true if a row was deleted.
     */
    public static function remove(int $productId, ?string $ownerKey = null): bool {
        if ($productId <= 0) return false;
        $owner = self::resolve($ownerKey);
        $stmt = Db::get()->prepare(
            "DELETE FROM wishlist_items WHERE owner_key = ? AND product_id = ?"
        );
        $stmt->execute([$owner, $productId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Toggle a product. Returns 'added' or 'removed' to reflect the new state.
     */
    public static function toggle(int $productId, ?string $ownerKey = null): string {
        $owner = self::resolve($ownerKey);
        if (self::has($productId, $owner)) {
            self::remove($productId, $owner);
            return 'removed';
        }
        self::add($productId, $owner);
        return 'added';
    }

    public static function has(int $productId, ?string $ownerKey = null): bool {
        if ($productId <= 0) return false;
        $owner = self::resolve($ownerKey);
        $stmt = Db::get()->prepare(
            "SELECT 1 FROM wishlist_items WHERE owner_key = ? AND product_id = ? LIMIT 1"
        );
        $stmt->execute([$owner, $productId]);
        return (bool)$stmt->fetchColumn();
    }

    public static function count(?string $ownerKey = null): int {
        $owner = self::resolve($ownerKey);
        $stmt = Db::get()->prepare(
            "SELECT COUNT(*) FROM wishlist_items WHERE owner_key = ?"
        );
        $stmt->execute([$owner]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Product ids saved by the owner, newest first.
     * @return int[]
     */
    public static function ids(?string $ownerKey = null): array {
        $owner = self::resolve($ownerKey);
        $stmt = Db::get()->prepare(
            "SELECT product_id FROM wishlist_items WHERE owner_key = ? ORDER BY id DESC"
        );
        $stmt->execute([$owner]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Enriched product rows for the owner's wishlist (active products only),
     * newest saved first. Missing/archived products are skipped silently.
     */
    public static function list(?string $ownerKey = null): array {
        $owner = self::resolve($ownerKey);
        $stmt = Db::get()->prepare("SELECT p.*
            FROM wishlist_items wi
            JOIN products p ON p.id = wi.product_id
            WHERE wi.owner_key = :owner AND p.store_id = :store AND p.status = 'active'
            ORDER BY wi.id DESC");
        $stmt->execute([':owner' => $owner, ':store' => StoreContext::current()->catalogId()]);
        $products = array_map([I18n::class, 'product'], $stmt->fetchAll(PDO::FETCH_ASSOC));
        return Products::enrichMany($products);
    }

    /**
     * Fold an anonymous session wishlist into a customer's wishlist on login.
     * Re-keys rows to the customer owner_key; duplicates are dropped.
     * Returns the number of rows moved.
     */
    public static function merge(string $sessionOwnerKey, int $customerId): int {
        if ($customerId <= 0 || strncmp($sessionOwnerKey, 's:', 2) !== 0) return 0;
        $customerKey = 'c:' . $customerId;
        $db = Db::get();
        $moved = 0;
        foreach (self::ids($sessionOwnerKey) as $pid) {
            if (self::add($pid, $customerKey)) $moved++;
        }
        $db->prepare("DELETE FROM wishlist_items WHERE owner_key = ?")
           ->execute([$sessionOwnerKey]);
        return $moved;
    }
}
