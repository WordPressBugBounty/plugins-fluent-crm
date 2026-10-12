<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
    "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" <?php language_attributes(); ?>>
<head>
    <meta http-equiv="Content-type" content="text/html; charset=utf-8"/>
    <meta http-equiv="Imagetoolbar" content="No"/>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php esc_html_e('Email Confirmation', 'fluent-crm') ?></title>
    <meta name="robots" content="noindex">
    <?php
        wp_head();
        do_action('fluent_crm/confirmation_head', $subscriber);
    ?>
</head>
<body>
<?php fluentCrm('view')->render('external.confirmation_content', ['body' => $body, 'business' => $business]); ?>
<?php
    wp_footer();
    do_action('fluent_crm/confirmation_footer', $subscriber);
?>
</body>
</html>
