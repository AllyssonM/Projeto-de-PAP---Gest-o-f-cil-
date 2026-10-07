<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ai_chat.php';
require_once __DIR__ . '/../includes/lang.php';

// O utilizador vem SEMPRE da sessão. Nada do que o navegador envia (user_id, etc.) é usado para isso.
$user = require_login();
$method = $_SERVER['REQUEST_METHOD'];
$uid = (int)$user['id'];

try {
    if ($method === 'GET') {
        if (!empty($_GET['latest'])) {                                   // reabrir a última conversa
            $conv = ai_latest_conversation($uid);
            json_response(['success' => true, 'mode' => ai_llm_available() ? 'ai' : 'basic',
                           'conversation_id' => $conv ? (int)$conv['id'] : null,
                           'messages' => $conv ? ai_conversation_messages($uid, (int)$conv['id']) : []]);
        }
        json_response(['success' => true, 'mode' => ai_llm_available() ? 'ai' : 'basic']);
    }

    $data = request_json();
    if (isset($data['lang'])) lumina_set_lang((string)$data['lang']);       // idioma da interface (pt, en, es): a Lumina responde nele

    if ($method === 'POST') {
        check_csrf();
        $conv = isset($data['conversation_id']) && $data['conversation_id'] !== null && $data['conversation_id'] !== '' ? (int)$data['conversation_id'] : null;
        session_write_close();                                          // a chamada à IA pode demorar: não bloquear a sessão
        $result = ai_chat_turn($user, (string)($data['message'] ?? ''), $conv);
        json_response(['success' => true] + $result);
    }

    if ($method === 'DELETE') {                                         // limpar (apagar) uma conversa própria
        check_csrf();
        $ok = ai_delete_conversation($uid, (int)($_GET['conversation_id'] ?? 0));
        json_response(['success' => $ok], $ok ? 200 : 404);
    }

    json_response(['success' => false, 'error' => L('Método não permitido.')], 405);
} catch (AiRateLimit $e) {
    json_response(['success' => false, 'error' => $e->getMessage()], 429);
} catch (AiUserError $e) {
    json_response(['success' => false, 'error' => $e->getMessage()], 400);
} catch (AiUnavailable $e) {
    json_response(['success' => false, 'error' => $e->getMessage()], 502);
} catch (Throwable $e) {
    error_log('[IA] ' . $e->getMessage());
    json_response(['success' => false, 'error' => L('Não foi possível processar o pedido agora.')], 500);
}
