<?php
/*
 * Shared confirmation page markup, also used by the double opt-in page preview.
 *
 * @var string $body
 * @var array $business
 */
?>
<div class="fluentcrm_unsubscribe_wrapper">
    <div class="fluentcrm_un_title">
        <?php if (!empty($business['logo'])): ?>
            <div class="fluentcrm_un_logo_wrapper">
                <img src="<?php echo esc_url($business['logo']); ?>" alt="<?php echo esc_attr($business['business_name']); ?>"/>
            </div>
        <?php else: ?>
        <h3><?php echo isset($business['business_name']) ? esc_html($business['business_name']) : ''; ?></h3>
        <?php endif; ?>
    </div>
    <div class="fluentcrm_un_form_wrapper">
        <?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </div>
</div>
