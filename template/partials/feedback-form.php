<section class="feedback-submit-layout" aria-labelledby="feedback-form-title">
    <form
        id="feedback-form"
        class="feedback-form-card"
        data-endpoint="/api/v2/support/tickets"
        data-msg-submitting="<?= $feedbackText('form.submitting') ?>"
        data-msg-files="<?= $feedbackText('form.files.error') ?>"
        data-msg-too-long="<?= $feedbackText('form.too_long') ?>"
        data-msg-created="<?= $feedbackText('form.created') ?>"
        data-msg-partial="<?= $feedbackText('form.partial', ['uploaded' => '{uploaded}']) ?>"
        data-msg-submit-failed="<?= $feedbackText('form.submit_error') ?>"
        data-msg-order-not-found="<?= $feedbackText('form.order_missing') ?>"
        data-msg-auth-required="<?= $feedbackText('form.auth_required') ?>"
        data-label-version="<?= $feedbackText('field.version') ?>"
        data-label-environment="<?= $feedbackText('field.environment') ?>"
        data-label-severity="<?= $feedbackText('field.severity') ?>"
        data-label-description="<?= $feedbackText('field.description') ?>"
    >
        <div class="feedback-form-heading">
            <div>
                <p class="portal-eyebrow"><?= $feedbackText('form.eyebrow') ?></p>
                <h2 id="feedback-form-title"><?= $feedbackText('form.title') ?></h2>
            </div>
            <a href="/feedback?view=submit#feedback-category-section"><?= $feedbackText('form.choose_category') ?></a>
        </div>

        <fieldset class="feedback-type-fieldset">
            <legend><?= $feedbackText('form.category') ?></legend>
            <div class="feedback-type-grid">
                <?php foreach ($feedbackCategories as $index => $category): ?>
                    <label class="feedback-type-card">
                        <input type="radio" name="category" value="<?= d2s_escape($category[0]) ?>"<?= $index === 0 ? ' checked' : '' ?> required>
                        <span class="feedback-type-mark" aria-hidden="true"><?= d2s_escape($category[0]) ?></span>
                        <strong><?= $feedbackText($category[1]) ?></strong>
                        <small><?= $feedbackText($category[2]) ?></small>
                    </label>
                <?php endforeach ?>
            </div>
        </fieldset>

        <div class="feedback-field-grid">
            <label class="feedback-field feedback-field-wide">
                <span><?= $feedbackText('field.subject') ?></span>
                <input name="subject" type="text" maxlength="200" required placeholder="<?= $feedbackText('field.subject.placeholder') ?>" autocomplete="off">
            </label>
            <label class="feedback-field">
                <span><?= $feedbackText('field.order') ?> <small><?= $feedbackText('field.optional') ?></small></span>
                <input name="order_no" type="text" inputmode="numeric" pattern="[0-9]{16}" maxlength="16" placeholder="0000000000000000" autocomplete="off">
                <small><?= $feedbackText('field.order.help') ?></small>
            </label>
            <label class="feedback-field">
                <span><?= $feedbackText('field.version') ?> <small><?= $feedbackText('field.optional') ?></small></span>
                <input name="software_version" type="text" maxlength="80" placeholder="<?= $feedbackText('field.version.placeholder') ?>" autocomplete="off">
            </label>
            <label class="feedback-field">
                <span><?= $feedbackText('field.environment') ?> <small><?= $feedbackText('field.optional') ?></small></span>
                <input name="environment" type="text" maxlength="120" placeholder="<?= $feedbackText('field.environment.placeholder') ?>" autocomplete="off">
            </label>
            <label class="feedback-field">
                <span><?= $feedbackText('field.severity') ?></span>
                <select name="severity">
                    <option value="low"><?= $feedbackText('severity.low') ?></option>
                    <option value="medium" selected><?= $feedbackText('severity.medium') ?></option>
                    <option value="high"><?= $feedbackText('severity.high') ?></option>
                </select>
            </label>
            <label class="feedback-field feedback-field-wide">
                <span><?= $feedbackText('field.description') ?></span>
                <textarea name="body" minlength="10" maxlength="4400" rows="9" required placeholder="<?= $feedbackText('field.description.placeholder') ?>"></textarea>
                <small><?= $feedbackText('field.sensitive') ?></small>
            </label>
        </div>

        <label class="feedback-upload">
            <span><?= $feedbackText('attachment.title') ?> <small><?= $feedbackText('attachment.optional') ?></small></span>
            <input id="feedback-files" name="files" type="file" accept=".pdf,.jpg,.jpeg,.png,.zip,.txt" multiple>
            <strong><?= $feedbackText('attachment.add') ?></strong>
            <small><?= $feedbackText('attachment.help') ?></small>
        </label>

        <label class="feedback-consent">
            <input name="agreement" type="checkbox" required>
            <span><?= $feedbackText('agreement.prefix') ?> <a href="/terms" target="_blank" rel="noopener noreferrer"><?= $feedbackText('agreement.terms') ?></a> <?= $feedbackText('agreement.join') ?> <a href="/privacy-policy" target="_blank" rel="noopener noreferrer"><?= $feedbackText('agreement.privacy') ?></a><?= $feedbackText('agreement.end') ?></span>
        </label>
        <label class="feedback-honeypot" aria-hidden="true"><?= $feedbackText('form.website') ?> <input name="website" type="text" tabindex="-1" autocomplete="off"></label>

        <p id="feedback-message" role="status" aria-live="polite"></p>
        <button id="feedback-submit" class="portal-primary feedback-submit" type="submit"><?= $feedbackText('form.submit') ?></button>
    </form>

    <aside class="feedback-side">
        <section class="feedback-side-card">
            <h2><?= $feedbackText('sidebar.preparation') ?></h2>
            <ul>
                <li><?= $feedbackText('sidebar.bug') ?></li>
                <li><?= $feedbackText('sidebar.order') ?></li>
                <li><?= $feedbackText('sidebar.license') ?></li>
                <li><?= $feedbackText('sidebar.sensitive') ?></li>
            </ul>
        </section>
        <section class="feedback-side-card feedback-response-card">
            <h2><?= $feedbackText('response.title') ?></h2>
            <dl>
                <div><dt><?= $feedbackText('response.first') ?></dt><dd><?= $feedbackText('response.first.value') ?></dd></div>
                <div><dt><?= $feedbackText('response.critical') ?></dt><dd><?= $feedbackText('response.critical.value') ?></dd></div>
                <div><dt><?= $feedbackText('response.retention') ?></dt><dd><?= $feedbackText('response.retention.value') ?></dd></div>
            </dl>
        </section>
        <section class="feedback-side-card feedback-privacy-card">
            <h2><?= $feedbackText('privacy.title') ?></h2>
            <p><?= $feedbackText('privacy.description') ?> <a href="/privacy-policy"><?= $feedbackText('privacy.policy') ?></a><?= $feedbackText('privacy.end') ?></p>
            <?php if (($feedbackChatUrl ?? '') !== ''): ?>
                <a class="site-secondary feedback-chat-link" href="/support/wechat"><?= $feedbackText('privacy.wechat') ?></a>
            <?php endif ?>
        </section>
    </aside>
</section>

<section id="feedback-success" class="feedback-success" aria-live="polite" hidden>
    <p class="portal-eyebrow"><?= $feedbackText('success.eyebrow') ?></p>
    <h2><?= $feedbackText('success.title') ?></h2>
    <p><?= $feedbackText('success.reference') ?><?= $feedbackText('success.reference_separator') ?><code id="feedback-reference"></code></p>
    <p><?= $feedbackText('success.description') ?></p>
    <div class="feedback-success-actions">
        <a class="site-primary" href="/tickets"><?= $feedbackText('success.view_tickets') ?></a>
        <button id="feedback-reset" class="site-secondary" type="button"><?= $feedbackText('success.again') ?></button>
    </div>
</section>
