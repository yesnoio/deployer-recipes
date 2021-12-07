<?php

/**
 * Applies update(s) to the Drupal database(s).
 *
 * @file
 */

declare(strict_types=1);

namespace Deployer;

task('cms:drupal:db:update', static function (): void {
    if (get('skip_db_ops') || get('skip_db_update')) {
        return;
    }

    $appPath = get('app_path');
    foreach (get('sites') as $site) {
        within($appPath . '/sites/' . $site, static function (): void {
            run('{{drush}} updb -y');
        });
    }
})->desc('Updates the Drupal DB based on Drupal install files.');
