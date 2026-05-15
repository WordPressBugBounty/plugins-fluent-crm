<?php return [
    '_ActionMenu.js' => [
        'file' => 'ActionMenu.js',
        'name' => 'ActionMenu',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '__plugin-vue_export-helper.js',
            '_notify.js'
        ]
    ],
    '_Animation.js' => [
        'file' => 'Animation.js',
        'name' => 'Animation',
        'imports' => [
            '_vendor.js',
            '__plugin-vue_export-helper.js'
        ],
        'css' => [
            'admin/css/Animation.css'
        ]
    ],
    '_BlockComposer.js' => [
        'file' => 'BlockComposer.js',
        'name' => 'BlockComposer',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_input-popover-dropdown.js',
            '__plugin-vue_export-helper.js',
            'resources/admin/app.js',
            '_notify.js',
            '_EmailPreview.js',
            '__MergeCodes.js',
            '_BuiltinTemplateDrawer.js',
            '_Storage.js'
        ],
        'css' => [
            'admin/css/BlockComposer.css'
        ]
    ],
    '_BuiltinTemplateDrawer.js' => [
        'file' => 'BuiltinTemplateDrawer.js',
        'name' => 'BuiltinTemplateDrawer',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_notify.js',
            '__plugin-vue_export-helper.js'
        ],
        'css' => [
            'admin/css/BuiltinTemplateDrawer.css'
        ]
    ],
    '_CompanyEditForm.js' => [
        'file' => 'CompanyEditForm.js',
        'name' => 'CompanyEditForm',
        'imports' => [
            'resources/admin/app.js',
            '_vendor-element-plus.js',
            '_vendor.js',
            '_notify.js',
            '__plugin-vue_export-helper.js'
        ],
        'dynamicImports' => [
            'resources/v3app/src/Modules/Settings/parts/CustomContactFields.vue'
        ]
    ],
    '_CompanyInfoSide.js' => [
        'file' => 'CompanyInfoSide.js',
        'name' => 'CompanyInfoSide',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '_CompanyEditForm.js',
            '_notify.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    '_Confirm.js' => [
        'file' => 'Confirm.js',
        'name' => 'Confirm',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    '_EmailComposer.js' => [
        'file' => 'EmailComposer.js',
        'name' => 'EmailComposer',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_BlockComposer.js',
            'resources/admin/app.js',
            '_TestEmail.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    '_EmailPreview.js' => [
        'file' => 'EmailPreview.js',
        'name' => 'EmailPreview',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_PreviewIframeBuilder.js',
            '__plugin-vue_export-helper.js',
            '_notify.js',
            '_TestEmail.js'
        ],
        'css' => [
            'admin/css/EmailPreview.css'
        ]
    ],
    '_EmailSubjects.js' => [
        'file' => 'EmailSubjects.js',
        'name' => 'EmailSubjects',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '__plugin-vue_export-helper.js',
            '__MailerConfig.js',
            '_notify.js'
        ],
        'css' => [
            'admin/css/EmailSubjects.css'
        ]
    ],
    '_FieldEditor.js' => [
        'file' => 'FieldEditor.js',
        'name' => 'FieldEditor',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '__plugin-vue_export-helper.js',
            '_EmailComposer.js',
            '_notify.js',
            '__MailerConfig.js',
            '_ItemCopier.js',
            '__MergeCodes.js'
        ],
        'dynamicImports' => [
            'resources/v3app/src/Modules/Contacts/RichFilters/_RichFilterContainer.vue'
        ]
    ],
    '_GenericPromo.js' => [
        'file' => 'GenericPromo.js',
        'name' => 'GenericPromo',
        'imports' => [
            'resources/admin/app.js',
            '_vendor.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    '_InlineDoc.js' => [
        'file' => 'InlineDoc.js',
        'name' => 'InlineDoc',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_notify.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    '_ItemCopier.js' => [
        'file' => 'ItemCopier.js',
        'name' => 'ItemCopier',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_notify.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    '_ItemCopier2.js' => [
        'file' => 'ItemCopier2.js',
        'name' => 'ItemCopier',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    '_PreviewIframeBuilder.js' => [
        'file' => 'PreviewIframeBuilder.js',
        'name' => 'PreviewIframeBuilder',
        'imports' => [
            '_vendor.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    '_ReadableRecipientTagger.js' => [
        'file' => 'ReadableRecipientTagger.js',
        'name' => 'ReadableRecipientTagger',
        'imports' => [
            'resources/admin/app.js',
            '_vendor-element-plus.js',
            '_vendor.js',
            '__plugin-vue_export-helper.js'
        ],
        'dynamicImports' => [
            'resources/admin/app.js'
        ]
    ],
    '_RecipientTaggerForm.js' => [
        'file' => 'RecipientTaggerForm.js',
        'name' => 'RecipientTaggerForm',
        'imports' => [
            'resources/admin/app.js',
            '_vendor-element-plus.js',
            '_vendor.js',
            '__plugin-vue_export-helper.js',
            '_notify.js'
        ],
        'dynamicImports' => [
            'resources/admin/app.js'
        ],
        'css' => [
            'admin/css/RecipientTaggerForm.css'
        ]
    ],
    '_Rest.js' => [
        'file' => 'Rest.js',
        'name' => 'Rest'
    ],
    '_SaveButton.js' => [
        'file' => 'SaveButton.js',
        'name' => 'SaveButton',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    '_SettingsHeader.js' => [
        'file' => 'SettingsHeader.js',
        'name' => 'SettingsHeader',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_notify.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    '_SettingsIcons.js' => [
        'file' => 'SettingsIcons.js',
        'name' => 'SettingsIcons',
        'imports' => [
            '_vendor.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    '_SettingsRow.js' => [
        'file' => 'SettingsRow.js',
        'name' => 'SettingsRow',
        'imports' => [
            '_vendor.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    '_SmsMessageCell.js' => [
        'file' => 'SmsMessageCell.js',
        'name' => 'SmsMessageCell',
        'imports' => [
            '_vendor.js',
            '__plugin-vue_export-helper.js',
            '_vendor-element-plus.js',
            '_notify.js'
        ]
    ],
    '_Storage.js' => [
        'file' => 'Storage.js',
        'name' => 'Storage'
    ],
    '_TestEmail.js' => [
        'file' => 'TestEmail.js',
        'name' => 'TestEmail',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_notify.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    '_TopNav.js' => [
        'file' => 'TopNav.js',
        'name' => 'TopNav',
        'imports' => [
            '_vendor.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    '__CampaignDetails.js' => [
        'file' => '_CampaignDetails.js',
        'name' => '_CampaignDetails',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '__plugin-vue_export-helper.js',
            '_ReadableRecipientTagger.js',
            '_PreviewIframeBuilder.js',
            '_notify.js'
        ]
    ],
    '__CustomSegementSettings.js' => [
        'file' => '_CustomSegementSettings.js',
        'name' => '_CustomSegementSettings',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '_notify.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    '__IndividualProgress.js' => [
        'file' => '_IndividualProgress.js',
        'name' => '_IndividualProgress',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '_notify.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    '__InlineCheckbox.js' => [
        'file' => '_InlineCheckbox.js',
        'name' => '_InlineCheckbox',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    '__LinkMetrics.js' => [
        'file' => '_LinkMetrics.js',
        'name' => '_LinkMetrics',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            'resources/admin/Modules/Email/Campaigns/_components/EmailPreview.vue',
            '_notify.js',
            '__plugin-vue_export-helper.js',
            '_SettingsIcons.js'
        ]
    ],
    '__MailerConfig.js' => [
        'file' => '_MailerConfig.js',
        'name' => '_MailerConfig',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    '__MergeCodes.js' => [
        'file' => '_MergeCodes.js',
        'name' => '_MergeCodes',
        'imports' => [
            '_input-popover-dropdown.js',
            '_vendor.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    '__StepPicker.js' => [
        'file' => '_StepPicker.js',
        'name' => '_StepPicker',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    '__conditions.js' => [
        'file' => '_conditions.js',
        'name' => '_conditions',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '__plugin-vue_export-helper.js',
            '_notify.js'
        ]
    ],
    '__plugin-vue_export-helper.js' => [
        'file' => '_plugin-vue_export-helper.js',
        'name' => '_plugin-vue_export-helper'
    ],
    '__report_widget.js' => [
        'file' => '_report_widget.js',
        'name' => '_report_widget',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_notify.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    '_input-popover-dropdown.js' => [
        'file' => 'input-popover-dropdown.js',
        'name' => 'input-popover-dropdown',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    '_notify.js' => [
        'file' => 'notify.js',
        'name' => 'notify',
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '__plugin-vue_export-helper.js'
        ],
        'assets' => [
            'fluentcrm-logo.png'
        ]
    ],
    '_vendor-element-plus.js' => [
        'file' => 'vendor-element-plus.js',
        'name' => 'vendor-element-plus',
        'imports' => [
            '_vendor.js'
        ],
        'css' => [
            'admin/css/vendor-element-plus.css'
        ]
    ],
    '_vendor.js' => [
        'file' => 'vendor.js',
        'name' => 'vendor',
        'isDynamicEntry' => true,
        'css' => [
            'admin/css/vendor.css'
        ],
        'assets' => [
            'flags.webp',
            'flags@2x.webp'
        ]
    ],
    'node_modules/.pnpm/intl-tel-input@26.9.2/node_modules/intl-tel-input/build/img/flags.webp' => [
        'file' => 'flags.webp',
        'src' => 'node_modules/.pnpm/intl-tel-input@26.9.2/node_modules/intl-tel-input/build/img/flags.webp'
    ],
    'node_modules/.pnpm/intl-tel-input@26.9.2/node_modules/intl-tel-input/build/img/flags@2x.webp' => [
        'file' => 'flags@2x.webp',
        'src' => 'node_modules/.pnpm/intl-tel-input@26.9.2/node_modules/intl-tel-input/build/img/flags@2x.webp'
    ],
    'resources/admin/Modules/Documentation/Docs.vue' => [
        'file' => 'admin/Modules/Documentation/Docs.js',
        'name' => 'Docs',
        'src' => 'resources/admin/Modules/Documentation/Docs.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_notify.js',
            'resources/admin/app.js',
            '__plugin-vue_export-helper.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/admin/Modules/Email/AllEmails.vue' => [
        'file' => 'admin/Modules/Email/AllEmails.js',
        'name' => 'AllEmails',
        'src' => 'resources/admin/Modules/Email/AllEmails.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            'resources/admin/Modules/Email/Campaigns/_components/EmailPreview.vue',
            '_TopNav.js',
            '_notify.js',
            '__plugin-vue_export-helper.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js',
            '_PreviewIframeBuilder.js'
        ]
    ],
    'resources/admin/Modules/Email/Campaigns/Campaign.vue' => [
        'file' => 'admin/Modules/Email/Campaigns/Campaign.js',
        'name' => 'Campaign',
        'src' => 'resources/admin/Modules/Email/Campaigns/Campaign.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_EmailSubjects.js',
            '__plugin-vue_export-helper.js',
            '_BlockComposer.js',
            '_RecipientTaggerForm.js',
            '_ReadableRecipientTagger.js',
            '_TestEmail.js',
            'resources/admin/app.js',
            '_notify.js',
            '_PreviewIframeBuilder.js',
            '__MailerConfig.js',
            '_input-popover-dropdown.js',
            '_EmailPreview.js',
            '__MergeCodes.js',
            '_BuiltinTemplateDrawer.js',
            '_Storage.js',
            '_Rest.js'
        ]
    ],
    'resources/admin/Modules/Email/Campaigns/Campaigns.vue' => [
        'file' => 'admin/Modules/Email/Campaigns/Campaigns.js',
        'name' => 'Campaigns',
        'src' => 'resources/admin/Modules/Email/Campaigns/Campaigns.vue',
        'isDynamicEntry' => true,
        'imports' => [
            'resources/admin/app.js',
            '_vendor-element-plus.js',
            '_vendor.js',
            '_InlineDoc.js',
            '__plugin-vue_export-helper.js',
            '_EmailPreview.js',
            '_TopNav.js',
            '_notify.js',
            '_Rest.js',
            '_Storage.js',
            '_input-popover-dropdown.js',
            '_PreviewIframeBuilder.js',
            '_TestEmail.js'
        ],
        'dynamicImports' => [
            'resources/v3app/src/Modules/Labels/Labels.vue',
            'resources/admin/app.js',
            'resources/admin/app.js'
        ]
    ],
    'resources/admin/Modules/Email/Campaigns/ViewCampaign.vue' => [
        'file' => 'admin/Modules/Email/Campaigns/ViewCampaign.js',
        'name' => 'ViewCampaign',
        'src' => 'resources/admin/Modules/Email/Campaigns/ViewCampaign.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '__LinkMetrics.js',
            'resources/admin/app.js',
            '__plugin-vue_export-helper.js',
            '__CampaignDetails.js',
            '_ReadableRecipientTagger.js',
            '_ItemCopier.js',
            '_notify.js',
            '_TestEmail.js',
            'resources/admin/Modules/Email/Campaigns/_components/EmailPreview.vue',
            '_PreviewIframeBuilder.js',
            '_SettingsIcons.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/admin/Modules/Email/Campaigns/_components/EmailPreview.vue' => [
        'file' => 'admin/Modules/Email/Campaigns/_components/EmailPreview.js',
        'name' => 'EmailPreview',
        'src' => 'resources/admin/Modules/Email/Campaigns/_components/EmailPreview.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_PreviewIframeBuilder.js',
            'resources/admin/app.js',
            '_notify.js',
            '__plugin-vue_export-helper.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/admin/Modules/Email/EmailSequences/AllSequences.vue' => [
        'file' => 'admin/Modules/Email/EmailSequences/AllSequences.js',
        'name' => 'AllSequences',
        'src' => 'resources/admin/Modules/Email/EmailSequences/AllSequences.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '__plugin-vue_export-helper.js',
            'resources/admin/app.js',
            '_InlineDoc.js',
            '_TopNav.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/admin/Modules/Email/EmailSequences/EditEmail.vue' => [
        'file' => 'admin/Modules/Email/EmailSequences/EditEmail.js',
        'name' => 'EditEmail',
        'src' => 'resources/admin/Modules/Email/EmailSequences/EditEmail.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_BlockComposer.js',
            'resources/admin/app.js',
            '_TestEmail.js',
            '__plugin-vue_export-helper.js',
            '_input-popover-dropdown.js',
            '_notify.js',
            '_EmailPreview.js',
            '_PreviewIframeBuilder.js',
            '__MergeCodes.js',
            '_BuiltinTemplateDrawer.js',
            '_Storage.js',
            '_Rest.js'
        ]
    ],
    'resources/admin/Modules/Email/EmailSequences/SequenceView.vue' => [
        'file' => 'admin/Modules/Email/EmailSequences/SequenceView.js',
        'name' => 'SequenceView',
        'src' => 'resources/admin/Modules/Email/EmailSequences/SequenceView.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_TopNav.js',
            'resources/admin/app.js',
            '_vendor.js',
            '__plugin-vue_export-helper.js',
            '_vendor-element-plus.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/admin/Modules/Email/EmailSequences/ViewSequence.vue' => [
        'file' => 'admin/Modules/Email/EmailSequences/ViewSequence.js',
        'name' => 'ViewSequence',
        'src' => 'resources/admin/Modules/Email/EmailSequences/ViewSequence.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '__LinkMetrics.js',
            '__MailerConfig.js',
            '_EmailPreview.js',
            'resources/admin/app.js',
            '_notify.js',
            '__plugin-vue_export-helper.js',
            'resources/admin/Modules/Email/Campaigns/_components/EmailPreview.vue',
            '_PreviewIframeBuilder.js',
            '_SettingsIcons.js',
            '_TestEmail.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/admin/Modules/Email/EmailSequences/ViewSequenceSubscribers.vue' => [
        'file' => 'admin/Modules/Email/EmailSequences/ViewSequenceSubscribers.js',
        'name' => 'ViewSequenceSubscribers',
        'src' => 'resources/admin/Modules/Email/EmailSequences/ViewSequenceSubscribers.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_RecipientTaggerForm.js',
            '__plugin-vue_export-helper.js',
            'resources/admin/app.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/admin/Modules/Email/EmailView.vue' => [
        'file' => 'admin/Modules/Email/EmailView.js',
        'name' => 'EmailView',
        'src' => 'resources/admin/Modules/Email/EmailView.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Modules/Email/RecurringCampaigns/Campaign/EmailConfiguration.vue' => [
        'file' => 'admin/Modules/Email/RecurringCampaigns/Campaign/EmailConfiguration.js',
        'name' => 'EmailConfiguration',
        'src' => 'resources/admin/Modules/Email/RecurringCampaigns/Campaign/EmailConfiguration.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_BlockComposer.js',
            'resources/admin/app.js',
            '__plugin-vue_export-helper.js',
            '_input-popover-dropdown.js',
            '_notify.js',
            '_EmailPreview.js',
            '_PreviewIframeBuilder.js',
            '_TestEmail.js',
            '__MergeCodes.js',
            '_BuiltinTemplateDrawer.js',
            '_Storage.js',
            '_Rest.js'
        ]
    ],
    'resources/admin/Modules/Email/RecurringCampaigns/Campaign/EmailHistory.vue' => [
        'file' => 'admin/Modules/Email/RecurringCampaigns/Campaign/EmailHistory.js',
        'name' => 'EmailHistory',
        'src' => 'resources/admin/Modules/Email/RecurringCampaigns/Campaign/EmailHistory.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_EmailPreview.js',
            'resources/admin/app.js',
            '__plugin-vue_export-helper.js',
            '_PreviewIframeBuilder.js',
            '_notify.js',
            '_TestEmail.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/admin/Modules/Email/RecurringCampaigns/Campaign/EmailReport.vue' => [
        'file' => 'admin/Modules/Email/RecurringCampaigns/Campaign/EmailReport.js',
        'name' => 'EmailReport',
        'src' => 'resources/admin/Modules/Email/RecurringCampaigns/Campaign/EmailReport.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_BlockComposer.js',
            '__plugin-vue_export-helper.js',
            '_EmailSubjects.js',
            '_TestEmail.js',
            'resources/admin/app.js',
            '__CampaignDetails.js',
            '__LinkMetrics.js',
            '_ReadableRecipientTagger.js',
            '_input-popover-dropdown.js',
            '_notify.js',
            '_EmailPreview.js',
            '_PreviewIframeBuilder.js',
            '__MergeCodes.js',
            '_BuiltinTemplateDrawer.js',
            '_Storage.js',
            '__MailerConfig.js',
            '_Rest.js',
            'resources/admin/Modules/Email/Campaigns/_components/EmailPreview.vue',
            '_SettingsIcons.js'
        ]
    ],
    'resources/admin/Modules/Email/RecurringCampaigns/Campaign/Settings.vue' => [
        'file' => 'admin/Modules/Email/RecurringCampaigns/Campaign/Settings.js',
        'name' => 'Settings',
        'src' => 'resources/admin/Modules/Email/RecurringCampaigns/Campaign/Settings.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_RecipientTaggerForm.js',
            '__conditions.js',
            '__plugin-vue_export-helper.js',
            'resources/admin/app.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/admin/Modules/Email/RecurringCampaigns/CreateFlow.vue' => [
        'file' => 'admin/Modules/Email/RecurringCampaigns/CreateFlow.js',
        'name' => 'CreateFlow',
        'src' => 'resources/admin/Modules/Email/RecurringCampaigns/CreateFlow.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_RecipientTaggerForm.js',
            '__conditions.js',
            'resources/admin/app.js',
            '__plugin-vue_export-helper.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/admin/Modules/Email/RecurringCampaigns/RecurringCampaigns.vue' => [
        'file' => 'admin/Modules/Email/RecurringCampaigns/RecurringCampaigns.js',
        'name' => 'RecurringCampaigns',
        'src' => 'resources/admin/Modules/Email/RecurringCampaigns/RecurringCampaigns.vue',
        'isDynamicEntry' => true,
        'imports' => [
            'resources/admin/app.js',
            '_vendor-element-plus.js',
            '_vendor.js',
            '__plugin-vue_export-helper.js',
            '_TopNav.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ],
        'dynamicImports' => [
            'resources/v3app/src/Modules/Labels/Labels.vue',
            'resources/admin/app.js',
            'resources/admin/app.js'
        ]
    ],
    'resources/admin/Modules/Email/RecurringCampaigns/RecurringCampaignsView.vue' => [
        'file' => 'admin/Modules/Email/RecurringCampaigns/RecurringCampaignsView.js',
        'name' => 'RecurringCampaignsView',
        'src' => 'resources/admin/Modules/Email/RecurringCampaigns/RecurringCampaignsView.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_TopNav.js',
            'resources/admin/app.js',
            '_vendor.js',
            '__plugin-vue_export-helper.js',
            '_vendor-element-plus.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/admin/Modules/Email/RecurringCampaigns/ViewSingleCampaign.vue' => [
        'file' => 'admin/Modules/Email/RecurringCampaigns/ViewSingleCampaign.js',
        'name' => 'ViewSingleCampaign',
        'src' => 'resources/admin/Modules/Email/RecurringCampaigns/ViewSingleCampaign.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_TestEmail.js',
            '__plugin-vue_export-helper.js',
            '_notify.js'
        ]
    ],
    'resources/admin/Modules/Email/Templates/EditPattern.vue' => [
        'file' => 'admin/Modules/Email/Templates/EditPattern.js',
        'name' => 'EditPattern',
        'src' => 'resources/admin/Modules/Email/Templates/EditPattern.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_BlockComposer.js',
            '__plugin-vue_export-helper.js',
            '_input-popover-dropdown.js',
            'resources/admin/app.js',
            '_notify.js',
            '_Rest.js',
            '_Storage.js',
            '_EmailPreview.js',
            '_PreviewIframeBuilder.js',
            '_TestEmail.js',
            '__MergeCodes.js',
            '_BuiltinTemplateDrawer.js'
        ]
    ],
    'resources/admin/Modules/Email/Templates/EditTemplate.vue' => [
        'file' => 'admin/Modules/Email/Templates/EditTemplate.js',
        'name' => 'EditTemplate',
        'src' => 'resources/admin/Modules/Email/Templates/EditTemplate.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_BlockComposer.js',
            'resources/admin/app.js',
            '_TestEmail.js',
            '_notify.js',
            '__plugin-vue_export-helper.js',
            '_input-popover-dropdown.js',
            '_EmailPreview.js',
            '_PreviewIframeBuilder.js',
            '__MergeCodes.js',
            '_BuiltinTemplateDrawer.js',
            '_Storage.js',
            '_Rest.js'
        ]
    ],
    'resources/admin/Modules/Email/Templates/Patterns.vue' => [
        'file' => 'admin/Modules/Email/Templates/Patterns.js',
        'name' => 'Patterns',
        'src' => 'resources/admin/Modules/Email/Templates/Patterns.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '_TopNav.js',
            '_notify.js',
            '__plugin-vue_export-helper.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/admin/Modules/Email/Templates/Templates.vue' => [
        'file' => 'admin/Modules/Email/Templates/Templates.js',
        'name' => 'Templates',
        'src' => 'resources/admin/Modules/Email/Templates/Templates.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '_notify.js',
            '__plugin-vue_export-helper.js',
            '_InlineDoc.js',
            '_EmailPreview.js',
            '_BuiltinTemplateDrawer.js',
            '_TopNav.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js',
            '_PreviewIframeBuilder.js',
            '_TestEmail.js'
        ]
    ],
    'resources/admin/Modules/Forms/Forms.vue' => [
        'file' => 'admin/Modules/Forms/Forms.js',
        'name' => 'Forms',
        'src' => 'resources/admin/Modules/Forms/Forms.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '__plugin-vue_export-helper.js',
            '_ItemCopier2.js',
            '_InlineDoc.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/admin/Modules/Funnels/FunnelActivities.vue' => [
        'file' => 'admin/Modules/Funnels/FunnelActivities.js',
        'name' => 'FunnelActivities',
        'src' => 'resources/admin/Modules/Funnels/FunnelActivities.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '__IndividualProgress.js',
            '_notify.js',
            '__plugin-vue_export-helper.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/admin/Modules/Funnels/FunnelEditor/Edit.vue' => [
        'file' => 'admin/Modules/Funnels/FunnelEditor/Edit.js',
        'name' => 'Edit',
        'src' => 'resources/admin/Modules/Funnels/FunnelEditor/Edit.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_FieldEditor.js',
            '_Animation.js',
            'resources/admin/app.js',
            '_notify.js',
            '__plugin-vue_export-helper.js',
            '__report_widget.js',
            '_InlineDoc.js',
            '_EmailComposer.js',
            '_BlockComposer.js',
            '_input-popover-dropdown.js',
            '_EmailPreview.js',
            '_PreviewIframeBuilder.js',
            '_TestEmail.js',
            '__MergeCodes.js',
            '_BuiltinTemplateDrawer.js',
            '_Storage.js',
            '__MailerConfig.js',
            '_ItemCopier.js',
            '_Rest.js'
        ],
        'css' => [
            'admin/Modules/Funnels/FunnelEditor/Edit.css'
        ]
    ],
    'resources/admin/Modules/Funnels/FunnelRoute.vue' => [
        'file' => 'admin/Modules/Funnels/FunnelRoute.js',
        'name' => 'FunnelRoute',
        'src' => 'resources/admin/Modules/Funnels/FunnelRoute.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Modules/Funnels/FunnelSubscribers.vue' => [
        'file' => 'admin/Modules/Funnels/FunnelSubscribers.js',
        'name' => 'FunnelSubscribers',
        'src' => 'resources/admin/Modules/Funnels/FunnelSubscribers.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '__IndividualProgress.js',
            '__plugin-vue_export-helper.js',
            '__report_widget.js',
            '__LinkMetrics.js',
            '_notify.js',
            '__StepPicker.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js',
            'resources/admin/Modules/Email/Campaigns/_components/EmailPreview.vue',
            '_PreviewIframeBuilder.js',
            '_SettingsIcons.js'
        ]
    ],
    'resources/admin/Modules/Funnels/Funnels.vue' => [
        'file' => 'admin/Modules/Funnels/Funnels.js',
        'name' => 'Funnels',
        'src' => 'resources/admin/Modules/Funnels/Funnels.vue',
        'isDynamicEntry' => true,
        'imports' => [
            'resources/admin/app.js',
            '_vendor-element-plus.js',
            '_vendor.js',
            '_notify.js',
            '__plugin-vue_export-helper.js',
            '_InlineDoc.js',
            '_Rest.js',
            '_Storage.js',
            '_input-popover-dropdown.js'
        ],
        'dynamicImports' => [
            'resources/v3app/src/Modules/Labels/Labels.vue',
            'resources/admin/app.js',
            'resources/admin/app.js'
        ]
    ],
    'resources/admin/Modules/Funnels/ImportFunnel.vue' => [
        'file' => 'admin/Modules/Funnels/ImportFunnel.js',
        'name' => 'ImportFunnel',
        'src' => 'resources/admin/Modules/Funnels/ImportFunnel.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_FieldEditor.js',
            '_notify.js',
            'resources/admin/app.js',
            '__plugin-vue_export-helper.js',
            '_EmailComposer.js',
            '_BlockComposer.js',
            '_input-popover-dropdown.js',
            '_EmailPreview.js',
            '_PreviewIframeBuilder.js',
            '_TestEmail.js',
            '__MergeCodes.js',
            '_BuiltinTemplateDrawer.js',
            '_Storage.js',
            '__MailerConfig.js',
            '_ItemCopier.js',
            '_Rest.js'
        ]
    ],
    'resources/admin/Modules/Funnels/parts/_LazyIndividualProgress.vue' => [
        'file' => 'admin/Modules/Funnels/parts/_LazyIndividualProgress.js',
        'name' => '_LazyIndividualProgress',
        'src' => 'resources/admin/Modules/Funnels/parts/_LazyIndividualProgress.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Modules/Importer/Importer.vue' => [
        'file' => 'admin/Modules/Importer/Importer.js',
        'name' => 'Importer',
        'src' => 'resources/admin/Modules/Importer/Importer.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Modules/Migrator/Home.vue' => [
        'file' => 'admin/Modules/Migrator/Home.js',
        'name' => 'Home',
        'src' => 'resources/admin/Modules/Migrator/Home.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '__plugin-vue_export-helper.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/admin/Modules/Profile/Parts/ProfileEmails.vue' => [
        'file' => 'admin/Modules/Profile/Parts/ProfileEmails.js',
        'name' => 'ProfileEmails',
        'src' => 'resources/admin/Modules/Profile/Parts/ProfileEmails.vue',
        'isDynamicEntry' => true,
        'imports' => [
            'resources/admin/app.js',
            '_vendor-element-plus.js',
            '_vendor.js',
            '_notify.js',
            '__plugin-vue_export-helper.js',
            '__StepPicker.js',
            '_EmailComposer.js',
            '__MailerConfig.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js',
            '_BlockComposer.js',
            '_EmailPreview.js',
            '_PreviewIframeBuilder.js',
            '_TestEmail.js',
            '__MergeCodes.js',
            '_BuiltinTemplateDrawer.js'
        ],
        'dynamicImports' => [
            'resources/admin/Modules/Funnels/parts/_LazyIndividualProgress.vue',
            'resources/admin/Modules/Email/Campaigns/_components/EmailPreview.vue'
        ]
    ],
    'resources/admin/Modules/Profile/Parts/ProfileFiles.vue' => [
        'file' => 'admin/Modules/Profile/Parts/ProfileFiles.js',
        'name' => 'ProfileFiles',
        'src' => 'resources/admin/Modules/Profile/Parts/ProfileFiles.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/admin/Modules/Profile/Parts/ProfileFormSubmissions.vue' => [
        'file' => 'admin/Modules/Profile/Parts/ProfileFormSubmissions.js',
        'name' => 'ProfileFormSubmissions',
        'src' => 'resources/admin/Modules/Profile/Parts/ProfileFormSubmissions.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '__plugin-vue_export-helper.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/admin/Modules/Profile/Parts/ProfileNotes.vue' => [
        'file' => 'admin/Modules/Profile/Parts/ProfileNotes.js',
        'name' => 'ProfileNotes',
        'src' => 'resources/admin/Modules/Profile/Parts/ProfileNotes.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '_notify.js',
            '__plugin-vue_export-helper.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/admin/Modules/Profile/Parts/ProfilePurchaseHistory.vue' => [
        'file' => 'admin/Modules/Profile/Parts/ProfilePurchaseHistory.js',
        'name' => 'ProfilePurchaseHistory',
        'src' => 'resources/admin/Modules/Profile/Parts/ProfilePurchaseHistory.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '__plugin-vue_export-helper.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/admin/Modules/Profile/Parts/ProfileSupportTickets.vue' => [
        'file' => 'admin/Modules/Profile/Parts/ProfileSupportTickets.js',
        'name' => 'ProfileSupportTickets',
        'src' => 'resources/admin/Modules/Profile/Parts/ProfileSupportTickets.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '__plugin-vue_export-helper.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/admin/Modules/Profile/Parts/SubscriberExternalView.vue' => [
        'file' => 'admin/Modules/Profile/Parts/SubscriberExternalView.js',
        'name' => 'SubscriberExternalView',
        'src' => 'resources/admin/Modules/Profile/Parts/SubscriberExternalView.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '__plugin-vue_export-helper.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/admin/Modules/Profile/Profile.vue' => [
        'file' => 'admin/Modules/Profile/Profile.js',
        'name' => 'Profile',
        'src' => 'resources/admin/Modules/Profile/Profile.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '_notify.js',
            '__plugin-vue_export-helper.js',
            '_CompanyEditForm.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ],
        'css' => [
            'admin/Modules/Profile/Profile.css'
        ]
    ],
    'resources/admin/Modules/Reports/Chart/world.json' => [
        'file' => 'admin/Modules/Reports/Chart/world.js',
        'name' => 'world',
        'src' => 'resources/admin/Modules/Reports/Chart/world.json',
        'isDynamicEntry' => true
    ],
    'resources/admin/Modules/Reports/ReportsHome.vue' => [
        'file' => 'admin/Modules/Reports/ReportsHome.js',
        'name' => 'ReportsHome',
        'src' => 'resources/admin/Modules/Reports/ReportsHome.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '__plugin-vue_export-helper.js',
            '_notify.js',
            'resources/admin/app.js',
            '_ItemCopier.js',
            'resources/admin/Modules/Funnels/parts/_LazyIndividualProgress.vue',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ],
        'dynamicImports' => [
            'resources/admin/Modules/Reports/Chart/world.json'
        ],
        'css' => [
            'admin/Modules/Reports/ReportsHome.css'
        ]
    ],
    'resources/admin/Modules/Settings/AddOns.vue' => [
        'file' => 'admin/Modules/Settings/AddOns.js',
        'name' => 'AddOns',
        'src' => 'resources/admin/Modules/Settings/AddOns.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_notify.js',
            '_ItemCopier.js',
            '__plugin-vue_export-helper.js',
            'resources/admin/app.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/admin/adminbar-search.js' => [
        'file' => 'admin/adminbar-search.js',
        'name' => 'adminbar-search',
        'src' => 'resources/admin/adminbar-search.js',
        'isEntry' => true
    ],
    'resources/admin/app.js' => [
        'file' => 'admin/app.js',
        'name' => 'app',
        'src' => 'resources/admin/app.js',
        'isEntry' => true,
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '__plugin-vue_export-helper.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ],
        'dynamicImports' => [
            '_vendor.js',
            'resources/v3app/src/Modules/Profile/Parts/_CustomFields.vue',
            'resources/admin/Modules/Email/EmailView.vue',
            'resources/admin/Modules/Email/Campaigns/Campaigns.vue',
            'resources/admin/Modules/Email/Campaigns/ViewCampaign.vue',
            'resources/admin/Modules/Email/Campaigns/Campaign.vue',
            'resources/admin/Modules/Email/Templates/Templates.vue',
            'resources/admin/Modules/Email/Templates/EditTemplate.vue',
            'resources/admin/Modules/Email/Templates/Patterns.vue',
            'resources/admin/Modules/Email/Templates/EditPattern.vue',
            'resources/admin/Modules/Email/EmailSequences/SequenceView.vue',
            'resources/admin/Modules/Email/EmailSequences/AllSequences.vue',
            'resources/admin/Modules/Email/EmailSequences/ViewSequence.vue',
            'resources/admin/Modules/Email/EmailSequences/EditEmail.vue',
            'resources/admin/Modules/Email/EmailSequences/ViewSequenceSubscribers.vue',
            'resources/admin/Modules/Email/RecurringCampaigns/RecurringCampaignsView.vue',
            'resources/admin/Modules/Email/RecurringCampaigns/RecurringCampaigns.vue',
            'resources/admin/Modules/Email/RecurringCampaigns/CreateFlow.vue',
            'resources/admin/Modules/Email/RecurringCampaigns/ViewSingleCampaign.vue',
            'resources/admin/Modules/Email/RecurringCampaigns/Campaign/EmailConfiguration.vue',
            'resources/admin/Modules/Email/RecurringCampaigns/Campaign/EmailHistory.vue',
            'resources/admin/Modules/Email/RecurringCampaigns/Campaign/Settings.vue',
            'resources/admin/Modules/Email/RecurringCampaigns/Campaign/EmailReport.vue',
            'resources/admin/Modules/Email/AllEmails.vue',
            'resources/v3app/src/Modules/Contacts/ContactGroups.vue',
            'resources/v3app/src/Modules/Lists/Lists.vue',
            'resources/v3app/src/Modules/Tags/Tags.vue',
            'resources/v3app/src/Modules/DynamicSegments/AllSegments.vue',
            'resources/v3app/src/Modules/DynamicSegments/CreateCustomSegment.vue',
            'resources/v3app/src/Modules/DynamicSegments/SegmentViewer.vue',
            'resources/v3app/src/Modules/Companies/CompaniesRoute.vue',
            'resources/v3app/src/Modules/Companies/AllCompanies.vue',
            'resources/v3app/src/Modules/Lists/List.vue',
            'resources/v3app/src/Modules/Tags/Tag.vue',
            'resources/admin/Modules/Importer/Importer.vue',
            'resources/admin/Modules/Forms/Forms.vue',
            'resources/v3app/src/Modules/Settings/Settings.vue',
            'resources/v3app/src/Modules/Settings/_EmailSettings.vue',
            'resources/v3app/src/Modules/Settings/_BusinessSetup.vue',
            'resources/v3app/src/Modules/Settings/_GeneralSettings.vue',
            'resources/v3app/src/Modules/Settings/parts/CustomContactFields.vue',
            'resources/v3app/src/Modules/Settings/SmartLinks/Links.vue',
            'resources/v3app/src/Modules/Settings/_SMSSettings.vue',
            'resources/v3app/src/Modules/Settings/_DoubleOptinSettings.vue',
            'resources/v3app/src/Modules/Settings/DeveloperWebhooks/_IncomingWebhooks.vue',
            'resources/v3app/src/Modules/Settings/SystemAdminTools/_CronJobMonitor.vue',
            'resources/v3app/src/Modules/Settings/SystemAdminTools/_DataCleanupPage.vue',
            'resources/v3app/src/Modules/Settings/SystemAdminTools/_DatabaseReset.vue',
            'resources/v3app/src/Modules/Settings/_Managers.vue',
            'resources/v3app/src/Modules/Settings/_ComplianceSettings.vue',
            'resources/v3app/src/Modules/Settings/_SmtpEmailSetup.vue',
            'resources/v3app/src/Modules/Settings/_LicenseManagement.vue',
            'resources/v3app/src/Modules/Settings/_IntegrationSettings.vue',
            'resources/v3app/src/Modules/Settings/_AdvancedFeatures.vue',
            'resources/v3app/src/Modules/Settings/_SystemLogs.vue',
            'resources/v3app/src/Modules/Settings/_AbandonedCartSettings.vue',
            'resources/v3app/src/Modules/Settings/_AiSettings.vue',
            'resources/v3app/src/Modules/Settings/_McpSettings.vue',
            'resources/admin/Modules/Funnels/FunnelRoute.vue',
            'resources/admin/Modules/Funnels/Funnels.vue',
            'resources/admin/Modules/Funnels/FunnelEditor/Edit.vue',
            'resources/admin/Modules/Funnels/FunnelSubscribers.vue',
            'resources/admin/Modules/Funnels/ImportFunnel.vue',
            'resources/admin/Modules/Funnels/FunnelActivities.vue',
            'resources/admin/Modules/Documentation/Docs.vue',
            'resources/admin/Modules/Settings/AddOns.vue',
            'resources/admin/Modules/Reports/ReportsHome.vue',
            'resources/admin/Modules/Migrator/Home.vue',
            'resources/v3app/src/Modules/SMS/Campaigns/Campaigns.vue',
            'resources/v3app/src/Modules/SMS/AllSMS.vue',
            'resources/v3app/src/Modules/SMS/Campaigns/CreateFlow.vue',
            'resources/v3app/src/Modules/SMS/Campaigns/CreateFlow.vue',
            'resources/v3app/src/Modules/SMS/Campaigns/ViewSMSCampaign.vue',
            'resources/v3app/src/Modules/SMS/Campaigns/Import.vue',
            'resources/admin/Modules/Profile/Profile.vue',
            'resources/v3app/src/Modules/Profile/Parts/ProfileOverview.vue',
            'resources/admin/Modules/Profile/Parts/ProfileEmails.vue',
            'resources/v3app/src/Modules/Profile/Parts/ProfileSMS.vue',
            'resources/admin/Modules/Profile/Parts/ProfileFormSubmissions.vue',
            'resources/admin/Modules/Profile/Parts/ProfileNotes.vue',
            'resources/admin/Modules/Profile/Parts/ProfilePurchaseHistory.vue',
            'resources/admin/Modules/Profile/Parts/ProfileSupportTickets.vue',
            'resources/admin/Modules/Profile/Parts/ProfileFiles.vue',
            'resources/admin/Modules/Profile/Parts/SubscriberExternalView.vue',
            'resources/v3app/src/Modules/Companies/ViewCompany.vue',
            'resources/v3app/src/Modules/Companies/CompanyContacts.vue',
            'resources/v3app/src/Modules/Companies/CompanyActivities.vue',
            'resources/v3app/src/Modules/Companies/CompanyExternalView.vue',
            'resources/admin/Modules/Email/Campaigns/Campaigns.vue',
            'resources/admin/Modules/Funnels/Funnels.vue',
            'resources/v3app/src/Modules/Contacts/ContactGroups.vue'
        ],
        'css' => [
            'admin/app.css'
        ]
    ],
    'resources/admin/boot.js' => [
        'file' => 'admin/boot.js',
        'name' => 'boot',
        'src' => 'resources/admin/boot.js',
        'isEntry' => true,
        'imports' => [
            '_Rest.js',
            '_Storage.js',
            '_vendor.js'
        ]
    ],
    'resources/admin/global_admin.js' => [
        'file' => 'admin/global_admin.js',
        'name' => 'global_admin',
        'src' => 'resources/admin/global_admin.js',
        'isEntry' => true
    ],
    'resources/admin/setup-wizard.js' => [
        'file' => 'admin/setup-wizard.js',
        'name' => 'setup-wizard',
        'src' => 'resources/admin/setup-wizard.js',
        'isEntry' => true,
        'imports' => [
            '_vendor.js',
            '_vendor-element-plus.js',
            '_notify.js',
            '__plugin-vue_export-helper.js',
            '_Rest.js'
        ]
    ],
    'resources/admin/visual-editor/visual-editor.js' => [
        'file' => 'admin/visual-editor/visual-editor.js',
        'name' => 'visual-editor',
        'src' => 'resources/admin/visual-editor/visual-editor.js',
        'isEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '__plugin-vue_export-helper.js',
            '__MergeCodes.js',
            '_input-popover-dropdown.js'
        ]
    ],
    'resources/images/fluentcrm-logo.png' => [
        'file' => 'fluentcrm-logo.png',
        'src' => 'resources/images/fluentcrm-logo.png'
    ],
    'resources/public/conditional-content-block.js' => [
        'file' => 'public/conditional-content-block.js',
        'name' => 'conditional-content-block',
        'src' => 'resources/public/conditional-content-block.js',
        'isEntry' => true
    ],
    'resources/public/public_pref.js' => [
        'file' => 'public/public_pref.js',
        'name' => 'public_pref',
        'src' => 'resources/public/public_pref.js',
        'isEntry' => true
    ],
    'resources/scss/admin_rtl.scss' => [
        'file' => 'admin/css/admin_rtl.css',
        'src' => 'resources/scss/admin_rtl.scss',
        'isEntry' => true
    ],
    'resources/scss/app_global.scss' => [
        'file' => 'admin/css/app_global.css',
        'src' => 'resources/scss/app_global.scss',
        'isEntry' => true
    ],
    'resources/scss/public_pref.scss' => [
        'file' => 'public/public_pref.css',
        'src' => 'resources/scss/public_pref.scss',
        'isEntry' => true
    ],
    'resources/scss/setup-wizard.scss' => [
        'file' => 'admin/css/setup-wizard.css',
        'src' => 'resources/scss/setup-wizard.scss',
        'isEntry' => true
    ],
    'resources/styles/app3.scss' => [
        'file' => 'admin/css/app3.css',
        'src' => 'resources/styles/app3.scss',
        'isEntry' => true
    ],
    'resources/v3app/src/Modules/Companies/AllCompanies.vue' => [
        'file' => 'v3app/src/Modules/Companies/AllCompanies.js',
        'name' => 'AllCompanies',
        'src' => 'resources/v3app/src/Modules/Companies/AllCompanies.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_CompanyInfoSide.js',
            'resources/admin/app.js',
            '_GenericPromo.js',
            '__plugin-vue_export-helper.js',
            '_notify.js',
            '_Confirm.js',
            '_CompanyEditForm.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/v3app/src/Modules/Companies/CompaniesRoute.vue' => [
        'file' => 'v3app/src/Modules/Companies/CompaniesRoute.js',
        'name' => 'CompaniesRoute',
        'src' => 'resources/v3app/src/Modules/Companies/CompaniesRoute.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/v3app/src/Modules/Companies/CompanyActivities.vue' => [
        'file' => 'v3app/src/Modules/Companies/CompanyActivities.js',
        'name' => 'CompanyActivities',
        'src' => 'resources/v3app/src/Modules/Companies/CompanyActivities.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_Confirm.js',
            'resources/admin/app.js',
            '_notify.js',
            '__plugin-vue_export-helper.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/v3app/src/Modules/Companies/CompanyContacts.vue' => [
        'file' => 'v3app/src/Modules/Companies/CompanyContacts.js',
        'name' => 'CompanyContacts',
        'src' => 'resources/v3app/src/Modules/Companies/CompanyContacts.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '__plugin-vue_export-helper.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/v3app/src/Modules/Companies/CompanyExternalView.vue' => [
        'file' => 'v3app/src/Modules/Companies/CompanyExternalView.js',
        'name' => 'CompanyExternalView',
        'src' => 'resources/v3app/src/Modules/Companies/CompanyExternalView.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/v3app/src/Modules/Companies/ViewCompany.vue' => [
        'file' => 'v3app/src/Modules/Companies/ViewCompany.js',
        'name' => 'ViewCompany',
        'src' => 'resources/v3app/src/Modules/Companies/ViewCompany.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_CompanyInfoSide.js',
            'resources/admin/app.js',
            '_Confirm.js',
            '__plugin-vue_export-helper.js',
            '_CompanyEditForm.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/v3app/src/Modules/Contacts/ContactGroups.vue' => [
        'file' => 'v3app/src/Modules/Contacts/ContactGroups.js',
        'name' => 'ContactGroups',
        'src' => 'resources/v3app/src/Modules/Contacts/ContactGroups.vue',
        'isDynamicEntry' => true,
        'imports' => [
            'resources/admin/app.js',
            '_vendor.js',
            '__plugin-vue_export-helper.js',
            '_vendor-element-plus.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/v3app/src/Modules/Contacts/RichFilters/_RichFilterContainer.vue' => [
        'file' => 'v3app/src/Modules/Contacts/RichFilters/_RichFilterContainer.js',
        'name' => '_RichFilterContainer',
        'src' => 'resources/v3app/src/Modules/Contacts/RichFilters/_RichFilterContainer.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '_notify.js',
            '__plugin-vue_export-helper.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/v3app/src/Modules/DynamicSegments/AllSegments.vue' => [
        'file' => 'v3app/src/Modules/DynamicSegments/AllSegments.js',
        'name' => 'AllSegments',
        'src' => 'resources/v3app/src/Modules/DynamicSegments/AllSegments.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '_notify.js',
            '__plugin-vue_export-helper.js',
            '_GenericPromo.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/v3app/src/Modules/DynamicSegments/CreateCustomSegment.vue' => [
        'file' => 'v3app/src/Modules/DynamicSegments/CreateCustomSegment.js',
        'name' => 'CreateCustomSegment',
        'src' => 'resources/v3app/src/Modules/DynamicSegments/CreateCustomSegment.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '__CustomSegementSettings.js',
            'resources/admin/app.js',
            '__plugin-vue_export-helper.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/v3app/src/Modules/DynamicSegments/SegmentViewer.vue' => [
        'file' => 'v3app/src/Modules/DynamicSegments/SegmentViewer.js',
        'name' => 'SegmentViewer',
        'src' => 'resources/v3app/src/Modules/DynamicSegments/SegmentViewer.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '__CustomSegementSettings.js',
            'resources/admin/app.js',
            '_notify.js',
            '__plugin-vue_export-helper.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/v3app/src/Modules/Labels/Labels.vue' => [
        'file' => 'v3app/src/Modules/Labels/Labels.js',
        'name' => 'Labels',
        'src' => 'resources/v3app/src/Modules/Labels/Labels.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '_notify.js',
            '__plugin-vue_export-helper.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/v3app/src/Modules/Lists/List.vue' => [
        'file' => 'v3app/src/Modules/Lists/List.js',
        'name' => 'List',
        'src' => 'resources/v3app/src/Modules/Lists/List.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/v3app/src/Modules/Lists/Lists.vue' => [
        'file' => 'v3app/src/Modules/Lists/Lists.js',
        'name' => 'Lists',
        'src' => 'resources/v3app/src/Modules/Lists/Lists.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_Confirm.js',
            '_ActionMenu.js',
            'resources/admin/app.js',
            '__plugin-vue_export-helper.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/v3app/src/Modules/Profile/Parts/ProfileOverview.vue' => [
        'file' => 'v3app/src/Modules/Profile/Parts/ProfileOverview.js',
        'name' => 'ProfileOverview',
        'src' => 'resources/v3app/src/Modules/Profile/Parts/ProfileOverview.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/v3app/src/Modules/Profile/Parts/_CustomFields.vue',
            '__plugin-vue_export-helper.js',
            'resources/admin/app.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/v3app/src/Modules/Profile/Parts/ProfileSMS.vue' => [
        'file' => 'v3app/src/Modules/Profile/Parts/ProfileSMS.js',
        'name' => 'ProfileSMS',
        'src' => 'resources/v3app/src/Modules/Profile/Parts/ProfileSMS.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '_notify.js',
            '__plugin-vue_export-helper.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/v3app/src/Modules/Profile/Parts/_CustomFields.vue' => [
        'file' => 'v3app/src/Modules/Profile/Parts/_CustomFields.js',
        'name' => '_CustomFields',
        'src' => 'resources/v3app/src/Modules/Profile/Parts/_CustomFields.vue',
        'isDynamicEntry' => true,
        'imports' => [
            'resources/admin/app.js',
            '_vendor-element-plus.js',
            '_vendor.js',
            '_notify.js',
            '__plugin-vue_export-helper.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ],
        'dynamicImports' => [
            'resources/v3app/src/Modules/Settings/parts/CustomContactFields.vue'
        ]
    ],
    'resources/v3app/src/Modules/SMS/AllSMS.vue' => [
        'file' => 'v3app/src/Modules/SMS/AllSMS.js',
        'name' => 'AllSMS',
        'src' => 'resources/v3app/src/Modules/SMS/AllSMS.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '_SmsMessageCell.js',
            '_notify.js',
            '__plugin-vue_export-helper.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/v3app/src/Modules/SMS/Campaigns/Campaigns.vue' => [
        'file' => 'v3app/src/Modules/SMS/Campaigns/Campaigns.js',
        'name' => 'Campaigns',
        'src' => 'resources/v3app/src/Modules/SMS/Campaigns/Campaigns.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            'resources/v3app/src/Modules/Labels/Labels.vue',
            '__plugin-vue_export-helper.js',
            '_SmsMessageCell.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/v3app/src/Modules/SMS/Campaigns/CreateFlow.vue' => [
        'file' => 'v3app/src/Modules/SMS/Campaigns/CreateFlow.js',
        'name' => 'CreateFlow',
        'src' => 'resources/v3app/src/Modules/SMS/Campaigns/CreateFlow.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_RecipientTaggerForm.js',
            '_ReadableRecipientTagger.js',
            'resources/admin/app.js',
            '__plugin-vue_export-helper.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/v3app/src/Modules/SMS/Campaigns/Import.vue' => [
        'file' => 'v3app/src/Modules/SMS/Campaigns/Import.js',
        'name' => 'Import',
        'src' => 'resources/v3app/src/Modules/SMS/Campaigns/Import.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/v3app/src/Modules/SMS/Campaigns/ViewSMSCampaign.vue' => [
        'file' => 'v3app/src/Modules/SMS/Campaigns/ViewSMSCampaign.js',
        'name' => 'ViewSMSCampaign',
        'src' => 'resources/v3app/src/Modules/SMS/Campaigns/ViewSMSCampaign.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_ReadableRecipientTagger.js',
            '__plugin-vue_export-helper.js',
            '_notify.js',
            'resources/admin/app.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/v3app/src/Modules/Settings/DeveloperWebhooks/_IncomingWebhooks.vue' => [
        'file' => 'v3app/src/Modules/Settings/DeveloperWebhooks/_IncomingWebhooks.js',
        'name' => '_IncomingWebhooks',
        'src' => 'resources/v3app/src/Modules/Settings/DeveloperWebhooks/_IncomingWebhooks.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_ItemCopier2.js',
            '__plugin-vue_export-helper.js',
            'resources/admin/app.js',
            '_notify.js',
            '_SettingsHeader.js',
            '_SettingsRow.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ],
        'assets' => [
            'incoming_webhook.png'
        ]
    ],
    'resources/v3app/src/Modules/Settings/Settings.vue' => [
        'file' => 'v3app/src/Modules/Settings/Settings.js',
        'name' => 'Settings',
        'src' => 'resources/v3app/src/Modules/Settings/Settings.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_SettingsIcons.js',
            '_notify.js',
            '_Animation.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/v3app/src/Modules/Settings/SmartLinks/Links.vue' => [
        'file' => 'v3app/src/Modules/Settings/SmartLinks/Links.js',
        'name' => 'Links',
        'src' => 'resources/v3app/src/Modules/Settings/SmartLinks/Links.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '__plugin-vue_export-helper.js',
            '_ItemCopier2.js',
            '_notify.js',
            '_SettingsHeader.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ],
        'assets' => [
            'smart_links.png'
        ]
    ],
    'resources/v3app/src/Modules/Settings/SystemAdminTools/_CronJobMonitor.vue' => [
        'file' => 'v3app/src/Modules/Settings/SystemAdminTools/_CronJobMonitor.js',
        'name' => '_CronJobMonitor',
        'src' => 'resources/v3app/src/Modules/Settings/SystemAdminTools/_CronJobMonitor.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_SettingsHeader.js',
            '__plugin-vue_export-helper.js',
            '_notify.js'
        ]
    ],
    'resources/v3app/src/Modules/Settings/SystemAdminTools/_DataCleanupPage.vue' => [
        'file' => 'v3app/src/Modules/Settings/SystemAdminTools/_DataCleanupPage.js',
        'name' => '_DataCleanupPage',
        'src' => 'resources/v3app/src/Modules/Settings/SystemAdminTools/_DataCleanupPage.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_notify.js',
            '__plugin-vue_export-helper.js',
            '_SettingsHeader.js'
        ]
    ],
    'resources/v3app/src/Modules/Settings/SystemAdminTools/_DatabaseReset.vue' => [
        'file' => 'v3app/src/Modules/Settings/SystemAdminTools/_DatabaseReset.js',
        'name' => '_DatabaseReset',
        'src' => 'resources/v3app/src/Modules/Settings/SystemAdminTools/_DatabaseReset.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_SettingsHeader.js',
            '_notify.js',
            '__plugin-vue_export-helper.js'
        ],
        'css' => [
            'v3app/src/Modules/Settings/SystemAdminTools/_DatabaseReset.css'
        ]
    ],
    'resources/v3app/src/Modules/Settings/_AbandonedCartSettings.vue' => [
        'file' => 'v3app/src/Modules/Settings/_AbandonedCartSettings.js',
        'name' => '_AbandonedCartSettings',
        'src' => 'resources/v3app/src/Modules/Settings/_AbandonedCartSettings.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '__InlineCheckbox.js',
            'resources/admin/app.js',
            '_SettingsHeader.js',
            '_SaveButton.js',
            '_notify.js',
            '_ItemCopier2.js',
            '_SettingsRow.js',
            '__plugin-vue_export-helper.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/v3app/src/Modules/Settings/_AdvancedFeatures.vue' => [
        'file' => 'v3app/src/Modules/Settings/_AdvancedFeatures.js',
        'name' => '_AdvancedFeatures',
        'src' => 'resources/v3app/src/Modules/Settings/_AdvancedFeatures.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_GenericPromo.js',
            '_SettingsHeader.js',
            '_SaveButton.js',
            '__plugin-vue_export-helper.js',
            'resources/admin/app.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/v3app/src/Modules/Settings/_AiSettings.vue' => [
        'file' => 'v3app/src/Modules/Settings/_AiSettings.js',
        'name' => '_AiSettings',
        'src' => 'resources/v3app/src/Modules/Settings/_AiSettings.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_SettingsHeader.js',
            '_SaveButton.js',
            '_SettingsRow.js',
            '__plugin-vue_export-helper.js',
            '_notify.js'
        ]
    ],
    'resources/v3app/src/Modules/Settings/_BusinessSetup.vue' => [
        'file' => 'v3app/src/Modules/Settings/_BusinessSetup.js',
        'name' => '_BusinessSetup',
        'src' => 'resources/v3app/src/Modules/Settings/_BusinessSetup.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_SettingsHeader.js',
            '_SaveButton.js',
            '_notify.js',
            '_SettingsRow.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/v3app/src/Modules/Settings/_ComplianceSettings.vue' => [
        'file' => 'v3app/src/Modules/Settings/_ComplianceSettings.js',
        'name' => '_ComplianceSettings',
        'src' => 'resources/v3app/src/Modules/Settings/_ComplianceSettings.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_SettingsHeader.js',
            '_SaveButton.js',
            '_SettingsRow.js',
            '__plugin-vue_export-helper.js',
            '_notify.js'
        ]
    ],
    'resources/v3app/src/Modules/Settings/_DoubleOptinSettings.vue' => [
        'file' => 'v3app/src/Modules/Settings/_DoubleOptinSettings.js',
        'name' => '_DoubleOptinSettings',
        'src' => 'resources/v3app/src/Modules/Settings/_DoubleOptinSettings.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '_SettingsHeader.js',
            '_SaveButton.js',
            '__plugin-vue_export-helper.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/v3app/src/Modules/Settings/_EmailSettings.vue' => [
        'file' => 'v3app/src/Modules/Settings/_EmailSettings.js',
        'name' => '_EmailSettings',
        'src' => 'resources/v3app/src/Modules/Settings/_EmailSettings.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '_ItemCopier2.js',
            '__plugin-vue_export-helper.js',
            '_SettingsHeader.js',
            '_SaveButton.js',
            '_SettingsRow.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/v3app/src/Modules/Settings/_GeneralSettings.vue' => [
        'file' => 'v3app/src/Modules/Settings/_GeneralSettings.js',
        'name' => '_GeneralSettings',
        'src' => 'resources/v3app/src/Modules/Settings/_GeneralSettings.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '__InlineCheckbox.js',
            'resources/admin/app.js',
            '__plugin-vue_export-helper.js',
            '_SettingsHeader.js',
            '_SaveButton.js',
            '_SettingsRow.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ],
        'assets' => [
            'woo_checkout_subscription.png'
        ]
    ],
    'resources/v3app/src/Modules/Settings/_IntegrationSettings.vue' => [
        'file' => 'v3app/src/Modules/Settings/_IntegrationSettings.js',
        'name' => '_IntegrationSettings',
        'src' => 'resources/v3app/src/Modules/Settings/_IntegrationSettings.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '_SettingsHeader.js',
            '_SaveButton.js',
            '_notify.js',
            '__plugin-vue_export-helper.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/v3app/src/Modules/Settings/_LicenseManagement.vue' => [
        'file' => 'v3app/src/Modules/Settings/_LicenseManagement.js',
        'name' => '_LicenseManagement',
        'src' => 'resources/v3app/src/Modules/Settings/_LicenseManagement.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_SettingsHeader.js',
            '__plugin-vue_export-helper.js',
            '_notify.js'
        ]
    ],
    'resources/v3app/src/Modules/Settings/_Managers.vue' => [
        'file' => 'v3app/src/Modules/Settings/_Managers.js',
        'name' => '_Managers',
        'src' => 'resources/v3app/src/Modules/Settings/_Managers.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '_notify.js',
            '_SettingsHeader.js',
            '_SettingsRow.js',
            '__plugin-vue_export-helper.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ],
        'assets' => [
            'crm_managers.png'
        ]
    ],
    'resources/v3app/src/Modules/Settings/_McpSettings.vue' => [
        'file' => 'v3app/src/Modules/Settings/_McpSettings.js',
        'name' => '_McpSettings',
        'src' => 'resources/v3app/src/Modules/Settings/_McpSettings.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_SettingsHeader.js',
            '_SettingsRow.js',
            '_ItemCopier.js',
            'resources/admin/app.js',
            '__plugin-vue_export-helper.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ],
        'css' => [
            'v3app/src/Modules/Settings/_McpSettings.css'
        ]
    ],
    'resources/v3app/src/Modules/Settings/_SMSSettings.vue' => [
        'file' => 'v3app/src/Modules/Settings/_SMSSettings.js',
        'name' => '_SMSSettings',
        'src' => 'resources/v3app/src/Modules/Settings/_SMSSettings.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_notify.js',
            '_SettingsHeader.js',
            '_SaveButton.js',
            '_ItemCopier2.js',
            'resources/admin/app.js',
            '_SettingsRow.js',
            '__plugin-vue_export-helper.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ],
        'assets' => [
            'sms_settings.png'
        ]
    ],
    'resources/v3app/src/Modules/Settings/_SmtpEmailSetup.vue' => [
        'file' => 'v3app/src/Modules/Settings/_SmtpEmailSetup.js',
        'name' => '_SmtpEmailSetup',
        'src' => 'resources/v3app/src/Modules/Settings/_SmtpEmailSetup.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_ItemCopier2.js',
            '_SettingsHeader.js',
            '__plugin-vue_export-helper.js',
            '_notify.js'
        ],
        'assets' => [
            'smtpemail.png'
        ]
    ],
    'resources/v3app/src/Modules/Settings/_SystemLogs.vue' => [
        'file' => 'v3app/src/Modules/Settings/_SystemLogs.js',
        'name' => '_SystemLogs',
        'src' => 'resources/v3app/src/Modules/Settings/_SystemLogs.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            'resources/admin/app.js',
            '_SettingsHeader.js',
            '_notify.js',
            '__plugin-vue_export-helper.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/v3app/src/Modules/Settings/parts/CustomContactFields.vue' => [
        'file' => 'v3app/src/Modules/Settings/parts/CustomContactFields.js',
        'name' => 'CustomContactFields',
        'src' => 'resources/v3app/src/Modules/Settings/parts/CustomContactFields.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_notify.js',
            '__plugin-vue_export-helper.js',
            '_SettingsHeader.js',
            'resources/admin/app.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/v3app/src/Modules/Tags/Tag.vue' => [
        'file' => 'v3app/src/Modules/Tags/Tag.js',
        'name' => 'Tag',
        'src' => 'resources/v3app/src/Modules/Tags/Tag.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor.js',
            '__plugin-vue_export-helper.js'
        ]
    ],
    'resources/v3app/src/Modules/Tags/Tags.vue' => [
        'file' => 'v3app/src/Modules/Tags/Tags.js',
        'name' => 'Tags',
        'src' => 'resources/v3app/src/Modules/Tags/Tags.vue',
        'isDynamicEntry' => true,
        'imports' => [
            '_vendor-element-plus.js',
            '_vendor.js',
            '_Confirm.js',
            '_ActionMenu.js',
            'resources/admin/app.js',
            '__plugin-vue_export-helper.js',
            '_notify.js',
            '_input-popover-dropdown.js',
            '_Rest.js',
            '_Storage.js'
        ]
    ],
    'resources/v3app/src/images/crm_managers.png' => [
        'file' => 'crm_managers.png',
        'src' => 'resources/v3app/src/images/crm_managers.png'
    ],
    'resources/v3app/src/images/incoming_webhook.png' => [
        'file' => 'incoming_webhook.png',
        'src' => 'resources/v3app/src/images/incoming_webhook.png'
    ],
    'resources/v3app/src/images/smart_links.png' => [
        'file' => 'smart_links.png',
        'src' => 'resources/v3app/src/images/smart_links.png'
    ],
    'resources/v3app/src/images/sms_settings.png' => [
        'file' => 'sms_settings.png',
        'src' => 'resources/v3app/src/images/sms_settings.png'
    ],
    'resources/v3app/src/images/smtpemail.png' => [
        'file' => 'smtpemail.png',
        'src' => 'resources/v3app/src/images/smtpemail.png'
    ],
    'resources/v3app/src/images/woo_checkout_subscription.png' => [
        'file' => 'woo_checkout_subscription.png',
        'src' => 'resources/v3app/src/images/woo_checkout_subscription.png'
    ]
];