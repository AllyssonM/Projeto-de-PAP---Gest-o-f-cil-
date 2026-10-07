<?php
/* =========================================================================
   ANEXOS: RECIBOS E FATURAS  (includes/attachments.php)
   -------------------------------------------------------------------------
   Peças partilhadas por api/attachments.php e pelos sítios que apagam registos:
   ATTACHMENT_ENTITIES  a que registos se pode anexar um ficheiro e que permissão é preciso para o ver;
   attachments_purge()  apaga os anexos (linhas e ficheiros) de um registo que vai ser eliminado.
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/uploads.php';

const ATTACHMENT_ENTITIES = [                       // entidade => [tabela, permissão que dá acesso]
    'transaction' => ['transactions', 'cashflow'],
    'bill'        => ['financial_documents', 'accounts'],
    'client'      => ['clients', 'clients'],
    'product'     => ['products', 'stock'],
];
const ATTACHMENT_MAX_PER_ITEM = 10;                  // ficheiros por movimento/conta/cliente/produto
const ATTACHMENT_MAX_BYTES_PER_BUSINESS = 300 * 1024 * 1024;   // espaço total por negócio (300 MB)

/** Apaga os anexos de um registo (as linhas e os ficheiros em disco). Chamar ANTES de apagar o registo. */
function attachments_purge(int $tenantId, string $entity, int $entityId): int
{
    $st = db()->prepare('SELECT id, path FROM attachments WHERE user_id = ? AND entity = ? AND entity_id = ?');
    $st->execute([$tenantId, $entity, $entityId]);
    $rows = $st->fetchAll();
    foreach ($rows as $r) upload_delete((string)$r['path']);
    if ($rows) db()->prepare('DELETE FROM attachments WHERE user_id = ? AND entity = ? AND entity_id = ?')->execute([$tenantId, $entity, $entityId]);
    return count($rows);
}
