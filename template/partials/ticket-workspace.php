<?php
declare(strict_types=1);
?>
        <header class="admin-page-heading">
            <div>
                <p class="portal-eyebrow"><?= d2s_tr('support.page.private_support') ?></p>
                <h1><?= d2s_tr('support.page.my_tickets') ?></h1>
                <p><?= d2s_tr('support.page.description') ?></p>
            </div>
        </header>
        <p id="ticket-message" role="status" aria-live="polite"></p>

        <section class="portal-card" aria-labelledby="ticket-list-title">
            <div class="support-title-row">
                <div>
                    <h2 id="ticket-list-title"><?= d2s_tr('support.list.title') ?></h2>
                    <p class="portal-help"><?= d2s_tr('support.list.reply_hint') ?></p>
                </div>
                <button id="ticket-refresh" type="button"><?= d2s_tr('support.action.refresh') ?></button>
            </div>
            <form id="ticket-search-form" class="ticket-search account-record-filter">
                <label for="ticket-search"><?= d2s_tr('support.search.label') ?></label>
                <input id="ticket-search" type="search" maxlength="200" placeholder="<?= d2s_tr('support.search.placeholder') ?>">
                <button type="submit"><?= d2s_tr('support.action.search') ?></button>
            </form>
            <div class="ticket-table-wrap account-records" role="region" aria-label="<?= d2s_tr('support.table.region') ?>" tabindex="0">
                <table class="ticket-table"><thead><tr>
                    <th><?= d2s_tr('support.table.category') ?></th><th><?= d2s_tr('support.table.number') ?></th>
                    <th><?= d2s_tr('support.table.subject') ?></th><th><?= d2s_tr('support.table.status') ?></th>
                    <th><?= d2s_tr('support.table.created') ?></th><th><?= d2s_tr('support.table.action') ?></th>
                </tr></thead><tbody id="ticket-list" aria-live="polite"></tbody></table>
            </div>
            <nav class="support-pager account-record-pager" aria-label="<?= d2s_tr('support.pager.label') ?>">
                <button id="ticket-previous" type="button"><?= d2s_tr('support.pager.previous') ?></button>
                <span id="ticket-page-summary"></span>
                <button id="ticket-next" type="button"><?= d2s_tr('support.pager.next') ?></button>
            </nav>
        </section>

        <section id="ticket-detail" class="portal-card support-detail" hidden aria-labelledby="ticket-detail-title">
            <div class="support-title-row">
                <div>
                    <p class="portal-eyebrow"><?= d2s_tr('support.detail.conversation') ?></p>
                    <h2 id="ticket-detail-title"><?= d2s_tr('support.detail.title') ?></h2>
                </div>
                <button id="ticket-detail-close" type="button"><?= d2s_tr('support.action.collapse') ?></button>
            </div>
            <ol id="ticket-progress" class="ticket-progress" aria-label="<?= d2s_tr('support.detail.progress') ?>"></ol>
            <div id="ticket-detail-summary" class="ticket-summary"></div>
            <div id="ticket-messages" class="support-messages"></div>

            <section class="support-reply-panel" aria-labelledby="ticket-reply-title">
                <div class="support-title-row">
                    <div>
                        <h3 id="ticket-reply-title"><?= d2s_tr('support.reply.title') ?></h3>
                        <p id="ticket-reply-state" class="support-reply-context"></p>
                    </div>
                    <a href="/feedback"><?= d2s_tr('support.reply.new_request') ?></a>
                </div>
                <form id="ticket-reply-form">
                    <label id="ticket-reply-editor-label" for="ticket-reply"><?= d2s_tr('support.reply.label') ?></label>
                    <textarea id="ticket-reply" name="body" minlength="1" maxlength="5000" rows="4" required placeholder="<?= d2s_tr('support.reply.placeholder') ?>"></textarea>
                    <div id="ticket-reply-editor" class="support-quill-editor" data-placeholder="<?= d2s_escape(d2s_tr('support.reply.placeholder')) ?>" aria-labelledby="ticket-reply-editor-label" hidden></div>
                    <p class="portal-help"><?= d2s_tr('support.reply.format_help') ?></p>
                    <div class="support-upload">
                        <label for="ticket-detail-files"><?= d2s_tr('support.attachment.upload') ?></label>
                        <input id="ticket-detail-files" type="file" accept=".pdf,.jpg,.jpeg,.png,.zip,.txt" multiple aria-describedby="ticket-file-help">
                        <p id="ticket-file-help" class="portal-help"><?= d2s_tr('support.attachment.help') ?></p>
                        <ul id="ticket-file-list" class="ticket-file-list" aria-live="polite"></ul>
                    </div>
                    <button class="portal-primary" type="submit"><?= d2s_tr('support.reply.title') ?></button>
                </form>
                <button id="ticket-close" type="button"><?= d2s_tr('support.action.close_ticket') ?></button>
            </section>
        </section>
