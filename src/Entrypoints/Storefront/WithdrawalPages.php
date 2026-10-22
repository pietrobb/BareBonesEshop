<?php
/** R8: storefront withdrawal function (?page=withdrawal) — identify the order, review, confirm. See Withdrawal. */
function renderWithdrawal(): string {
    $t = static fn(string $key, array $vars = []): string => I18n::format($key, $vars);
    $values = [
        'order_number' => mb_substr(Security::str($_POST['order_number'] ?? $_GET['order'] ?? ''), 0, 64),
        'email' => mb_substr(Security::str($_POST['email'] ?? ''), 0, 191),
        'name' => mb_substr(Security::str($_POST['name'] ?? ''), 0, 191),
        'reason' => mb_substr(Security::str($_POST['reason'] ?? ''), 0, 2000),
    ];
    $view = ['error' => '', 'done' => '', 'review' => false, 'show_form' => true, 'items' => []];
    $step = $_SERVER['REQUEST_METHOD'] === 'POST' ? Security::str($_POST['withdrawal_step'] ?? '') : '';
    if ($step !== '') {
        if (!Security::csrfValidate()) {
            http_response_code(403);
            $view['error'] = $t('withdrawal.expired');
        } elseif (!Security::rateLimit('withdrawal', 20, 600)) {
            http_response_code(429);
            $view['error'] = $t('withdrawal.throttled');
        } else {
            $order = Withdrawal::findOrder($values['order_number'], $values['email']);
            $refusal = $order === null ? 'not_found' : Withdrawal::refusal($order);
            if ($refusal === null && $step === 'confirm') {
                $itemIds = array_map('intval', array_filter((array)($_POST['items'] ?? []), 'is_scalar'));
                $result = Withdrawal::submit($order, $values['name'], $itemIds, $values['reason']);
                $refusal = $result['error'];
                if ($result['ok']) {
                    $view['done'] = $t($result['confirmation'] === 'sent' ? 'withdrawal.done' : 'withdrawal.done_no_mail',
                        ['at' => $result['received_at'] . ' UTC', 'email' => (string)$order['customer_email'], 'order' => (string)$order['order_number']]);
                    $view['show_form'] = false;
                }
            } elseif ($refusal === null) {
                $view['review'] = true;
                $view['show_form'] = false;
                if ($values['name'] === '') $values['name'] = (string)$order['customer_name'];
                $view['items'] = array_map(static fn(array $item): array => [
                    'id' => (int)$item['id'], 'name' => (string)$item['name'], 'quantity' => (int)$item['quantity'],
                ], Withdrawal::openItems($order));
            }
            if ($refusal !== null) {
                if ($refusal === 'not_found') http_response_code(422);
                $view['error'] = $t('withdrawal.' . $refusal);
            }
        }
    }
    return Template::render('storefront/withdrawal', $view + [
        'title' => $t('withdrawal.title'),
        'intro' => $t('withdrawal.intro', ['days' => (string)Withdrawal::PERIOD_DAYS]),
        'csrf_token' => Security::csrfToken(),
        'values' => $values,
        'order_number_label' => $t('withdrawal.order_number'),
        'email_label' => $t('withdrawal.email'),
        'name_label' => $t('withdrawal.name'),
        'reason_label' => $t('withdrawal.reason'),
        'continue_label' => $t('withdrawal.continue'),
        'review_hint' => $t('withdrawal.review_hint'),
        'items_label' => $t('withdrawal.items'),
        'confirm_label' => $t('withdrawal.confirm'),
    ]);
}
