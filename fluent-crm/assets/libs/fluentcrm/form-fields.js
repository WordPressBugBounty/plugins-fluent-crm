/**
 * Public form field initializers (date pickers, multi-selects, combodate, date dropdowns).
 * Copied to assets/libs by the build and enqueued by FormElementBuilder. Strings come from window.fluentcrmFormFields.i18n.
 */
(function () {
    'use strict';

    var i18n = (window.fluentcrmFormFields && window.fluentcrmFormFields.i18n) || {};

    function initDatePickers() {
        if (typeof window.flatpickr === 'undefined') {
            return;
        }
        document.querySelectorAll('.fc-js-date-picker').forEach(function (picker) {
            if (picker.dataset.fpInitialized) {
                return;
            }
            window.flatpickr(picker, { dateFormat: 'Y-m-d', allowInput: true });
            picker.dataset.fpInitialized = '1';
        });
        document.querySelectorAll('.fc-js-datetime-picker').forEach(function (picker) {
            if (picker.dataset.fpInitialized) {
                return;
            }
            window.flatpickr(picker, {
                enableTime: true,
                dateFormat: 'Y-m-d H:i:S',
                time_24hr: true,
                allowInput: true
            });
            picker.dataset.fpInitialized = '1';
        });
    }

    function initMultiSelects() {
        if (typeof window.Choices === 'undefined') {
            return;
        }
        document.querySelectorAll('.fc-js-choice-multi').forEach(function (select) {
            if (select.dataset.choicesInitialized) {
                return;
            }
            new window.Choices(select, {
                removeItemButton: true,
                placeholderValue: select.dataset.placeholder || i18n.selectOptions || 'Select options',
                searchEnabled: true,
                itemSelectText: '',
                noResultsText: i18n.noResults || 'No matching options found',
                noChoicesText: i18n.noChoices || 'No options available'
            });
            select.dataset.choicesInitialized = '1';
        });
    }

    function initComboDates() {
        var $ = window.jQuery;
        if (!$ || !$.fn || !$.fn.combodate) {
            return;
        }
        $('.fc-js-combodate').each(function () {
            if (this.dataset.combodateInitialized) {
                return;
            }
            $(this).combodate();
            this.dataset.combodateInitialized = '1';
        });
    }

    function initDateDropdowns() {
        document.querySelectorAll('.fc_date_dropdowns').forEach(function (wrap) {
            if (wrap.dataset.ddInitialized) {
                return;
            }
            var hidden = wrap.querySelector('input[type="hidden"]');
            var daySelect = wrap.querySelector('[data-role="day"]');
            var monthSelect = wrap.querySelector('[data-role="month"]');
            var yearSelect = wrap.querySelector('[data-role="year"]');
            if (!hidden || !daySelect || !monthSelect || !yearSelect) {
                return;
            }
            function daysInMonth(month, year) {
                if (!month || !year) {
                    return 31;
                }
                return new Date(parseInt(year, 10), parseInt(month, 10), 0).getDate();
            }
            function updateDayOptions() {
                var maxDay = daysInMonth(monthSelect.value, yearSelect.value);
                var currentDay = parseInt(daySelect.value, 10) || 0;
                var options = daySelect.querySelectorAll('option');
                for (var i = 1; i < options.length; i++) {
                    var val = parseInt(options[i].value, 10);
                    options[i].disabled = val > maxDay;
                    if (val > maxDay && currentDay === val) {
                        currentDay = maxDay;
                    }
                }
                if (currentDay > maxDay) {
                    daySelect.value = String(maxDay);
                }
            }
            function sync() {
                var d = parseInt(daySelect.value, 10);
                var m = parseInt(monthSelect.value, 10);
                var y = parseInt(yearSelect.value, 10);
                if (d && m && y) {
                    var maxDay = daysInMonth(m, y);
                    if (d > maxDay) {
                        d = maxDay;
                    }
                    hidden.value = y + '-' + (m < 10 ? '0' + m : m) + '-' + (d < 10 ? '0' + d : d);
                } else {
                    hidden.value = '';
                }
            }
            monthSelect.addEventListener('change', function () { updateDayOptions(); sync(); });
            yearSelect.addEventListener('change', function () { updateDayOptions(); sync(); });
            daySelect.addEventListener('change', sync);
            updateDayOptions();
            sync();
            wrap.dataset.ddInitialized = '1';
        });
    }

    function initAll() {
        // A throw in one initializer must not stop the others.
        [initDatePickers, initMultiSelects, initComboDates, initDateDropdowns].forEach(function (init) {
            try {
                init();
            } catch (e) {
                if (window.console && console.error) {
                    console.error('[fluentcrm] form field init failed:', e);
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
