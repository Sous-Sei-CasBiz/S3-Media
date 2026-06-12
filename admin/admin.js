/* global jQuery, wpS3Media */
(function ($) {
    'use strict';

    var $btn     = $('#s3-offload-start');
    var $status  = $('#s3-offload-status');
    var $barWrap = $('#s3-offload-bar-wrap');
    var $bar     = $('#s3-offload-bar');
    var $errors  = $('#s3-offload-errors');

    var totalPending = 0;
    var processed    = 0;
    var running      = false;

    $btn.on('click', function () {
        if (running) return;

        $errors.empty();
        processed = 0;
        running   = true;
        $btn.prop('disabled', true).text('Running…');
        $barWrap.show();

        // Step 1: fetch the total count, then start batching
        $.post(wpS3Media.ajaxUrl, {
            action : 'wp_s3_offload_count',
            nonce  : wpS3Media.nonce
        }, function (res) {
            if (!res.success) {
                finish('Error fetching count: ' + (res.data || 'unknown'));
                return;
            }
            totalPending = res.data.pending;
            if (totalPending === 0) {
                finish('All media is already on S3.');
                return;
            }
            $status.text('0 / ' + totalPending + ' offloaded');
            processBatch(0);
        });
    });

    function processBatch(offset) {
        $.post(wpS3Media.ajaxUrl, {
            action : 'wp_s3_offload_batch',
            nonce  : wpS3Media.nonce,
            offset : offset
        }, function (res) {
            if (!res.success) {
                finish('Server error: ' + (res.data || 'unknown'));
                return;
            }

            var data = res.data;

            processed += data.processed.length;
            updateProgress(processed, totalPending);

            if (data.errors && data.errors.length) {
                data.errors.forEach(function (err) {
                    $errors.append('<li>Attachment #' + err.id + ': ' + err.message + '</li>');
                });
            }

            if (data.has_more) {
                // Continue with the next batch
                processBatch(offset + data.processed.length + data.errors.length);
            } else {
                finish('Done — ' + processed + ' file(s) offloaded to S3.');
            }
        }).fail(function () {
            finish('Network error. Please try again.');
        });
    }

    function updateProgress(done, total) {
        var pct = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 100;
        $bar.css('width', pct + '%');
        $status.text(done + ' / ' + total + ' offloaded (' + pct + '%)');
    }

    function finish(message) {
        running = false;
        $btn.prop('disabled', false).text('Start offload');
        $status.text(message);
        $bar.css('width', '100%');
    }

}(jQuery));
