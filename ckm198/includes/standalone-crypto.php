<?php
if (!defined('ABSPATH')) exit;

/**
 * Ed25519 verification layer.
 *
 * IMPORTANT: the bundled key is a DEVELOPMENT verification key for this preview.
 * The private key is intentionally not shipped in the plugin. Before a commercial
 * release the public key must be replaced by the production public key generated
 * and stored only on Облачный сервис.
 */
const CKM_QUIZ_PRO_ED25519_KEY_ID = 'ckm-dev-2026-09-15-03';
const CKM_QUIZ_PRO_ED25519_PUBLIC_KEY_B64URL = 'uuoKaAaxC05fZGNmWxs5qytLOXZg0iDOWHZhNU73u3o';

function ckm_quiz_pro_crypto_supported(): bool {
    return function_exists('sodium_crypto_sign_verify_detached');
}

function ckm_quiz_pro_b64url_decode(string $value): string|false {
    $value = trim($value);
    if ($value === '') return false;
    $pad = strlen($value) % 4;
    if ($pad) $value .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($value, '-_', '+/'), true);
}

function ckm_quiz_pro_canonicalize(mixed $value): mixed {
    if (!is_array($value)) return $value;
    if (array_is_list($value)) {
        $out=[];
        foreach ($value as $v) $out[] = ckm_quiz_pro_canonicalize($v);
        return $out;
    }
    ksort($value, SORT_STRING);
    $out=[];
    foreach ($value as $k=>$v) $out[(string)$k] = ckm_quiz_pro_canonicalize($v);
    return $out;
}

function ckm_quiz_pro_canonical_json(mixed $value): string|false {
    $json = wp_json_encode(
        ckm_quiz_pro_canonicalize($value),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    return is_string($json) ? $json : false;
}

function ckm_quiz_pro_public_keyring(): array {
    // Production builds should normally contain only the active production key
    // plus, during planned rotation, one previous/next key.
    return [
        CKM_QUIZ_PRO_ED25519_KEY_ID => CKM_QUIZ_PRO_ED25519_PUBLIC_KEY_B64URL,
    ];
}

function ckm_quiz_pro_verify_signed_envelope(array $envelope, string $requiredSchema): array|WP_Error {
    if (!ckm_quiz_pro_crypto_supported()) {
        return new WP_Error('ed25519_unavailable','На сервере PHP недоступна функция Ed25519 (ext-sodium).');
    }
    $schema = isset($envelope['schema']) ? (string)$envelope['schema'] : '';
    $keyId = isset($envelope['key_id']) ? (string)$envelope['key_id'] : '';
    $payload = $envelope['payload'] ?? null;
    $signatureB64 = isset($envelope['signature']) ? (string)$envelope['signature'] : '';
    if ($schema !== $requiredSchema || $keyId === '' || !is_array($payload) || $signatureB64 === '') {
        return new WP_Error('signed_envelope_invalid','Неполный или неверный формат подписанного ответа.');
    }
    $keys = ckm_quiz_pro_public_keyring();
    if (empty($keys[$keyId])) {
        return new WP_Error('unknown_signing_key','Ответ подписан неизвестным ключом.');
    }
    $publicKey = ckm_quiz_pro_b64url_decode((string)$keys[$keyId]);
    $signature = ckm_quiz_pro_b64url_decode($signatureB64);
    if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ||
        $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
        return new WP_Error('signature_encoding_invalid','Некорректная кодировка Ed25519-ключа или подписи.');
    }
    $message = ckm_quiz_pro_canonical_json([
        'schema'=>$schema,
        'key_id'=>$keyId,
        'payload'=>$payload,
    ]);
    if ($message === false || !sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
        return new WP_Error('signature_invalid','Ed25519-подпись не прошла проверку.');
    }
    return $payload;
}

function ckm_quiz_pro_parse_utc(string $value): int|false {
    if ($value === '') return false;
    $ts = strtotime($value);
    return $ts === false ? false : $ts;
}

function ckm_quiz_pro_verify_license_response(array $envelope, array $expected=[]): array|WP_Error {
    $payload = ckm_quiz_pro_verify_signed_envelope($envelope,'ckm.license-response.v1');
    if (is_wp_error($payload)) return $payload;

    foreach (['status','plan','domain','installation_id','issued_at','valid_until'] as $required) {
        if (!array_key_exists($required,$payload)) return new WP_Error('license_payload_invalid','В signed license-response отсутствует поле '.$required.'.');
    }
    if (!empty($expected['domain']) && strtolower((string)$payload['domain']) !== strtolower((string)$expected['domain'])) {
        return new WP_Error('license_domain_mismatch','Подписанная лицензия выдана для другого домена.');
    }
    if (!empty($expected['installation_id']) && !hash_equals((string)$expected['installation_id'],(string)$payload['installation_id'])) {
        return new WP_Error('license_installation_mismatch','Подписанная лицензия выдана для другой установки.');
    }
    if (array_key_exists('request_nonce',$expected) && (string)($payload['request_nonce']??'') !== (string)$expected['request_nonce']) {
        return new WP_Error('license_nonce_mismatch','Ответ лицензии не соответствует текущему запросу.');
    }
    $now=time();
    $issued=ckm_quiz_pro_parse_utc((string)$payload['issued_at']);
    $validUntil=ckm_quiz_pro_parse_utc((string)$payload['valid_until']);
    if ($issued===false || $validUntil===false || $issued>$now+300 || $validUntil<$now) {
        return new WP_Error('license_response_stale','Подписанный ответ лицензии просрочен или имеет неверное время.');
    }
    return $payload;
}

function ckm_quiz_pro_verify_update_manifest(array $envelope, array $expected=[]): array|WP_Error {
    $payload = ckm_quiz_pro_verify_signed_envelope($envelope,'ckm.update-manifest.v1');
    if (is_wp_error($payload)) return $payload;

    foreach (['plugin_slug','version','package_url','sha256','issued_at','valid_until'] as $required) {
        if (!array_key_exists($required,$payload)) return new WP_Error('update_payload_invalid','В signed update-manifest отсутствует поле '.$required.'.');
    }
    if ((string)$payload['plugin_slug'] !== 'ckm-quiz-pro') {
        return new WP_Error('update_slug_mismatch','Update-manifest относится к другому плагину.');
    }
    if (!empty($expected['request_nonce']) && (string)($payload['request_nonce']??'') !== (string)$expected['request_nonce']) {
        return new WP_Error('update_nonce_mismatch','Update-manifest не соответствует текущему запросу.');
    }
    if (!preg_match('/^[a-f0-9]{64}$/i',(string)$payload['sha256'])) {
        return new WP_Error('update_hash_invalid','В update-manifest отсутствует корректный SHA-256.');
    }
    if (!wp_http_validate_url((string)$payload['package_url'])) {
        return new WP_Error('update_url_invalid','В update-manifest указан некорректный URL пакета.');
    }
    $now=time();
    $issued=ckm_quiz_pro_parse_utc((string)$payload['issued_at']);
    $validUntil=ckm_quiz_pro_parse_utc((string)$payload['valid_until']);
    if ($issued===false || $validUntil===false || $issued>$now+300 || $validUntil<$now) {
        return new WP_Error('update_manifest_stale','Подписанный update-manifest просрочен или имеет неверное время.');
    }
    return $payload;
}

function ckm_quiz_pro_load_fixture(string $name): array|WP_Error {
    $file=CKM_QUIZ_PRO_DIR.'fixtures/'.$name;
    if (!is_readable($file)) return new WP_Error('fixture_missing','Не найден тестовый подписанный fixture: '.$name);
    $decoded=json_decode((string)file_get_contents($file),true);
    return is_array($decoded) ? $decoded : new WP_Error('fixture_invalid','Некорректный JSON fixture: '.$name);
}

function ckm_quiz_pro_crypto_self_test(): array {
    $checks=[];
    $checks[]=['Ed25519 / ext-sodium',ckm_quiz_pro_crypto_supported(),ckm_quiz_pro_crypto_supported()?'available':'missing'];

    $license=ckm_quiz_pro_load_fixture('signed-license-response.json');
    $licensePayload=is_wp_error($license)?$license:ckm_quiz_pro_verify_license_response($license);
    $checks[]=['Подпись license-response',!is_wp_error($licensePayload),is_wp_error($licensePayload)?$licensePayload->get_error_message():'verified'];
    if (is_array($license)) {
        $tampered=$license;
        $tampered['payload']['status']='suspended';
        $bad=ckm_quiz_pro_verify_signed_envelope($tampered,'ckm.license-response.v1');
        $checks[]=['Подмена license-response отклоняется',is_wp_error($bad),is_wp_error($bad)?'tamper rejected':'ОШИБКА: подмена принята'];
    }

    $update=ckm_quiz_pro_load_fixture('signed-update-manifest.json');
    $updatePayload=is_wp_error($update)?$update:ckm_quiz_pro_verify_update_manifest($update);
    $checks[]=['Подпись update-manifest',!is_wp_error($updatePayload),is_wp_error($updatePayload)?$updatePayload->get_error_message():'verified'];
    if (is_array($update)) {
        $tampered=$update;
        $tampered['payload']['version']='99.99.99';
        $bad=ckm_quiz_pro_verify_signed_envelope($tampered,'ckm.update-manifest.v1');
        $checks[]=['Подмена update-manifest отклоняется',is_wp_error($bad),is_wp_error($bad)?'tamper rejected':'ОШИБКА: подмена принята'];
    }
    $ent=ckm_quiz_pro_load_fixture('signed-entitlements-response.json');
    $entPayload=is_wp_error($ent)?$ent:ckm_quiz_pro_verify_entitlements_response($ent);
    $checks[]=['Подпись entitlements catalog',!is_wp_error($entPayload),is_wp_error($entPayload)?$entPayload->get_error_message():'verified'];
    if (is_array($ent)) {
        $tampered=$ent; $tampered['payload']['packages'][0]['entitlement']='revoked';
        $bad=ckm_quiz_pro_verify_signed_envelope($tampered,'ckm.entitlements.v1');
        $checks[]=['Подмена entitlements отклоняется',is_wp_error($bad),is_wp_error($bad)?'tamper rejected':'ОШИБКА: подмена принята'];
    }
    return $checks;
}
