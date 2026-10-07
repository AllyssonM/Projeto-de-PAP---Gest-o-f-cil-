<?php
/* =========================================================================
   UPLOAD SEGURO DE IMAGENS  (includes/uploads.php)
   -------------------------------------------------------------------------
   O QUE FAZ:  valida, guarda e entrega as fotos de perfil e as logos das empresas.
   PORQUE É IMPORTANTE: um ficheiro enviado pelo utilizador nunca é de confiança.
   O QUE VERIFICA:
     1. O tamanho máximo (mensagem amigável se passar).
     2. O TIPO REAL do ficheiro, lido do conteúdo (não do nome nem do que o
        navegador diz). Um .exe renomeado para .png é recusado.
     3. Que é mesmo uma imagem válida, com dimensões razoáveis.
     4. SVG (só para logos): limpo de scripts, ligações externas e eventos.
     5. Se o PHP tiver a extensão GD, a imagem é recodificada (tira metadados,
        como a localização GPS de uma fotografia).
   ONDE GUARDA: pasta storage/ (sem acesso por URL). O nome é aleatório.
   COMO ENTREGA: api/files.php, só a quem tem sessão e é do mesmo negócio.
   ========================================================================= */
declare(strict_types=1);

/** Erro com mensagem própria para mostrar ao utilizador. */
final class UploadError extends RuntimeException {}

const STORAGE_DIR = __DIR__ . '/../storage';
const IMAGE_MAX_PIXELS = 6000;          // largura/altura máxima aceite

/** Regras de cada tipo de imagem. */
function upload_rules(string $kind): array
{
    if ($kind === 'receipt') {                                                          // recibos e faturas anexados a movimentos, contas, clientes e produtos
        return ['mimes' => ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'application/pdf' => 'pdf'], 'max' => 5 * 1024 * 1024, 'label' => 'JPG, PNG, WEBP ou PDF', 'folder' => 'receipts'];
    }
    return $kind === 'logo'
        ? ['mimes' => ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/svg+xml' => 'svg'], 'max' => 1024 * 1024, 'label' => 'PNG, JPG ou SVG', 'folder' => 'logos']
        : ['mimes' => ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'], 'max' => 2 * 1024 * 1024, 'label' => 'JPG, PNG ou WEBP', 'folder' => 'avatars'];
}

/**
 * Valida o ficheiro recebido em $_FILES[...]. Devolve [bytes, mime, extensão] ou lança UploadError.
 */
function upload_validate(?array $file, string $kind): array
{
    $rules = upload_rules($kind);
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new UploadError($kind === 'receipt' ? 'Escolhe um ficheiro (foto ou PDF) do teu dispositivo.' : 'Escolhe uma imagem do teu dispositivo.');
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) throw new UploadError('A imagem é demasiado grande (máximo ' . round($rules['max'] / 1048576) . ' MB).');
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new UploadError('Não foi possível receber a imagem. Tenta outra vez.');
    if ($file['size'] > $rules['max']) throw new UploadError('A imagem é demasiado grande (máximo ' . round($rules['max'] / 1048576) . ' MB).');

    $bytes = (string)file_get_contents($file['tmp_name']);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';          // tipo REAL, pelo conteúdo
    if ($mime === 'text/xml' || $mime === 'text/plain' || $mime === 'application/xml') {    // o finfo vê o SVG como texto/XML
        $mime = stripos(substr($bytes, 0, 2000), '<svg') !== false ? 'image/svg+xml' : $mime;
    }
    if (!isset($rules['mimes'][$mime])) throw new UploadError('Formato não suportado. Usa ' . $rules['label'] . '.');

    if ($mime === 'application/pdf') {
        if (!str_starts_with($bytes, '%PDF-')) throw new UploadError('O ficheiro não é um PDF válido.');          // PDF: só se confirma a assinatura (é servido sempre como descarga)
    } elseif ($mime === 'image/svg+xml') {
        $bytes = upload_clean_svg($bytes);
    } else {
        $info = @getimagesizefromstring($bytes);
        if (!$info || ($info['mime'] ?? '') !== $mime) throw new UploadError('O ficheiro não é uma imagem válida.');
        if ($info[0] > IMAGE_MAX_PIXELS || $info[1] > IMAGE_MAX_PIXELS) throw new UploadError('A imagem tem dimensões demasiado grandes (máximo ' . IMAGE_MAX_PIXELS . ' px).');
        $bytes = upload_reencode($bytes, $mime);
    }
    return [$bytes, $mime, $rules['mimes'][$mime]];
}

/** Com GD: recodifica a imagem (apaga metadados e qualquer conteúdo escondido). Sem GD: devolve a original já validada. */
function upload_reencode(string $bytes, string $mime): string
{
    if (!function_exists('imagecreatefromstring')) return $bytes;
    $img = @imagecreatefromstring($bytes);
    if (!$img) throw new UploadError('O ficheiro não é uma imagem válida.');
    imagesavealpha($img, true);
    ob_start();
    $okEncode = match ($mime) { 'image/png' => imagepng($img), 'image/jpeg' => imagejpeg($img, null, 88), 'image/webp' => function_exists('imagewebp') && imagewebp($img, null, 85), default => false };
    $out = (string)ob_get_clean();
    imagedestroy($img);
    return ($okEncode && $out !== '') ? $out : $bytes;
}

/**
 * Limpa um SVG: só fica o desenho. Remove scripts, objetos incorporados, eventos (onclick...)
 * e ligações para fora. Se algo não bater certo, recusa.
 */
function upload_clean_svg(string $svg): string
{
    // Sem a extensão DOM do PHP não há como limpar um SVG com segurança (e limpar com expressões regulares não é fiável).
    // Nesse caso recusamos: é a opção segura. O XAMPP normal tem esta extensão ativa.
    if (!class_exists('DOMDocument')) throw new UploadError('Este servidor não permite logos SVG. Usa PNG ou JPG.');
    if (strlen($svg) > 300 * 1024) throw new UploadError('O SVG é demasiado grande.');
    if (preg_match('/<!(DOCTYPE|ENTITY)/i', $svg)) throw new UploadError('SVG não permitido (contém definições externas).');
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $loaded = $dom->loadXML($svg, LIBXML_NONET | LIBXML_NOENT * 0);          // sem acesso à rede, sem entidades
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    if (!$loaded || !$dom->documentElement || strtolower($dom->documentElement->localName) !== 'svg') throw new UploadError('O ficheiro SVG não é válido.');

    $blocked = ['script', 'foreignobject', 'iframe', 'object', 'embed', 'audio', 'video', 'style', 'animate', 'set', 'use'];
    $xpath = new DOMXPath($dom);
    foreach (iterator_to_array($xpath->query('//*')) as $node) {
        if (in_array(strtolower($node->localName), $blocked, true)) { $node->parentNode?->removeChild($node); continue; }
        foreach (iterator_to_array($node->attributes ?? []) as $attr) {
            $name = strtolower($attr->name); $value = strtolower(trim($attr->value));
            $external = in_array($name, ['href', 'xlink:href', 'src'], true);
            if (str_starts_with($name, 'on') || $external || str_contains($value, 'javascript:') || str_contains($value, 'url(') && !preg_match('/^url\(#[\w-]+\)$/', $value)) {
                $node->removeAttributeNode($attr);
            }
        }
    }
    $clean = $dom->saveXML($dom->documentElement);
    if (!$clean) throw new UploadError('O ficheiro SVG não é válido.');
    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $clean;
}

/** Guarda a imagem com nome aleatório. Devolve o caminho relativo (ex.: avatars/9f3c....png). */
function upload_store(string $kind, string $bytes, string $ext): string
{
    $folder = upload_rules($kind)['folder'];
    $dir = STORAGE_DIR . '/' . $folder;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new UploadError('Não foi possível guardar a imagem (sem permissão na pasta storage).');
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (@file_put_contents($dir . '/' . $name, $bytes, LOCK_EX) === false) throw new UploadError('Não foi possível guardar a imagem.');
    return $folder . '/' . $name;
}

/** Apaga uma imagem guardada. Só aceita caminhos no formato esperado (nunca "../"). */
function upload_delete(?string $rel): void
{
    if ($rel && preg_match('#^(avatars|logos|receipts)/[a-f0-9]{32}\.(png|jpg|webp|svg|pdf)$#', $rel)) @unlink(STORAGE_DIR . '/' . $rel);
}

/** Entrega o ficheiro ao navegador com os cabeçalhos de segurança certos. Imagens abrem na página; PDF é sempre descarregado ($downloadName = nome sugerido). */
function upload_send(?string $rel, ?string $downloadName = null): never
{
    if (!$rel || !preg_match('#^(avatars|logos|receipts)/[a-f0-9]{32}\.(png|jpg|webp|svg|pdf)$#', $rel) || !is_file(STORAGE_DIR . '/' . $rel)) {
        http_response_code(404); exit;
    }
    $ext = pathinfo($rel, PATHINFO_EXTENSION);
    $types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'pdf' => 'application/pdf'];
    header('Content-Type: ' . $types[$ext]);
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; sandbox');   // mesmo aberto à parte, um SVG ou PDF não corre nada
    if ($ext === 'pdf') {
        $safe = trim(preg_replace('/[^A-Za-z0-9._-]+/', '_', $downloadName ?: 'documento.pdf'), '._') ?: 'documento.pdf';
        header('Content-Disposition: attachment; filename="' . (str_ends_with(strtolower($safe), '.pdf') ? $safe : $safe . '.pdf') . '"');
    }
    header('Cache-Control: private, max-age=3600');
    header('Content-Length: ' . filesize(STORAGE_DIR . '/' . $rel));
    readfile(STORAGE_DIR . '/' . $rel);
    exit;
}
