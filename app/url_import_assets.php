<?php
declare(strict_types=1);

/** Per-request registry; never remove media created by another request. */
function url_import_record_asset(array $asset): void
{
    if (!isset($GLOBALS['howhub_url_import_pending']) || !is_array($GLOBALS['howhub_url_import_pending'])) return;
    if (isset($asset['id'])) $GLOBALS['howhub_url_import_pending'][] = (int)$asset['id'];
}

/** Undo URL-import media storage when a user cancels or the AI rejects content. */
function url_import_discard_assets(PDO $pdo): void
{
    $ids = array_values(array_unique((array)($GLOBALS['howhub_url_import_pending'] ?? [])));
    unset($GLOBALS['howhub_url_import_pending']);
    foreach (array_reverse($ids) as $id) {
        if ($id < 1) continue;
        try {
            media_delete($pdo, (int)$id);
        } catch (Throwable $e) {
            diag_event('warning','url_import','cleanup','failure',['asset_id'=>(int)$id,'type'=>get_class($e)]);
        }
    }
}
