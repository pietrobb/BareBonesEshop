<?php
/** R8: storefront complaint form (?page=complaint) — identify the order, then the line, defect and remedy. See Complaints. */
function renderComplaint(): string {
    $t = static fn(string $key, array $vars = []): string => I18n::format($key, $vars);
    $values = [
        'order_number' => mb_substr(Security::str($_POST['order_number'] ?? $_GET['order'] ?? ''), 0, 64),
        'email' => mb_substr(Security::str($_POST['email'] ?? ''), 0, 191),
        'name' => mb_substr(Security::str($_POST['name'] ?? ''), 0, 191),
        'defect' => mb_substr(Security::str($_POST['defect'] ?? ''), 0, 4000),
        'remedy' => Security::clean($_POST['remedy'] ?? ''),
    ];
    $view = ['error' => '', 'done' => '', 'details' => false, 'show_form' => true, 'items' => []];
    $step = $_SERVER['REQUEST_METHOD'] === 'POST' ? Security::str($_POST['complaint_step'] ?? '') : '';
    if ($step !== '') {
        if (!Security::csrfValidate()) {
            http_response_code(403);
            $view['error'] = $t('withdrawal.expired');
        } elseif (!Security::rateLimit('complaint', 20, 600)) {
            http_response_code(429);
            $view['error'] = $t('withdrawal.throttled');
        } else {
            $order = Withdrawal::findOrder($values['order_number'], $values['email']);
            $refusal = $order === null ? 'not_found' : Complaints::refusal($order);
            if ($refusal === null && $step === 'submit') {
                $result = Complaints::submit($order, Security::int($_POST['item'] ?? 0), $values['name'], $values['defect'], $values['remedy']);
                $refusal = $result['error'];
                if ($result['ok']) {
                    $view['done'] = $t($result['confirmation'] === 'sent' ? 'complaint.done' : 'complaint.done_no_mail', [
                        'number' => $result['number'], 'at' => $result['received_at'] . ' UTC', 'email' => (string)$order['customer_email'],
                    ]);
                    $view['show_form'] = false;
                }
            }
            if ($refusal === null || in_array($refusal, ['defect_required', 'remedy_required', 'no_items'], true)) {
                if ($view['done'] === '') {
                    $view['details'] = true;
                    $view['show_form'] = false;
                    if ($values['name'] === '') $values['name'] = (string)$order['customer_name'];
                    $view['items'] = array_map(static fn(array $item): array => ['id' => (int)$item['id'], 'name' => (string)$item['name']], Orders::items((int)$order['id']));
                }
            }
            if ($refusal !== null) {
                if ($refusal === 'not_found') http_response_code(422);
                $view['error'] = $t($refusal === 'not_found' ? 'withdrawal.not_found' : 'complaint.' . $refusal, ['months' => (string)ConsumerNotice::LIABILITY_MONTHS]);
            }
        }
    }
    $remedies = array_map(static fn(string $r): array => ['value' => $r, 'label' => $t('complaint.remedy.' . $r), 'selected' => $values['remedy'] === $r], Complaints::REMEDIES);
    return Template::render('storefront/complaint', $view + [
        'title' => $t('complaint.title'),
        'intro' => $t('complaint.intro', ['months' => (string)ConsumerNotice::LIABILITY_MONTHS]),
        'csrf_token' => Security::csrfToken(),
        'values' => $values,
        'remedies' => $remedies,
        'order_number_label' => $t('withdrawal.order_number'),
        'email_label' => $t('withdrawal.email'),
        'name_label' => $t('withdrawal.name'),
        'item_label' => $t('complaint.item'),
        'defect_label' => $t('complaint.defect'),
        'remedy_label' => $t('complaint.remedy'),
        'continue_label' => $t('withdrawal.continue'),
        'submit_label' => $t('complaint.submit'),
    ]);
}
