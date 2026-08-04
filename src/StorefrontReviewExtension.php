<?php
final class StorefrontReviewExtension
{
    public static function register(): void
    {
        if (!class_exists(Reviews::class) || StorefrontViewModelRegistry::has('product', 'reviews.product')) return;
        StorefrontViewModelRegistry::register('product', 'reviews.product', static function(array $context): array {
            $productId = (int)$context['model']['product']['id'];
            $aggregate = Reviews::aggregate($productId);
            $translations = I18n::all();
            return [
                'title' => $translations['reviews'], 'empty_label' => $translations['reviews.none'],
                'items' => array_map(static fn(array $review): array => [
                    'id' => (int)$review['id'], 'author_name' => (string)$review['author_name'],
                    'rating' => (int)$review['rating'], 'title' => (string)$review['title'],
                    'body' => (string)$review['body'], 'created_at' => (string)$review['created_at'],
                ], Reviews::approved($productId)),
                'count' => (int)$aggregate['count'],
                'average' => (int)$aggregate['count'] > 0 ? (float)$aggregate['avg'] : null,
                'submit_url' => Template::url('/api.php?action=review_submit'),
                'csrf_token' => Security::csrfToken(), 'requires_author' => empty($_SESSION['customer_id']),
                'form_title' => $translations['reviews.write'], 'rating_label' => $translations['reviews.rating'],
                'author_label' => $translations['reviews.author'], 'review_title_label' => $translations['reviews.review_title'],
                'body_label' => $translations['reviews.body'], 'submit_label' => $translations['reviews.submit'],
                'success_label' => $translations['reviews.success'], 'error_label' => $translations['reviews.error'],
            ];
        });
    }
}
