<?php

/**
 * Magento 2 Deployer recipe.
 *
 * @file
 */

declare(strict_types=1);

namespace Deployer;

use Deployer\Exception\RunException;

require_once __DIR__ . '/../../src/functions.php';
import('recipe/common.php');

set('app_type', 'magento');
set('mage', 'bin/magento');
fill('shared_dirs', [
    'var',
    'pub/media',
    'pub/page-cache',
    'pub/sitemap',
    'pub/static',
    'generated',
]);
fill('shared_files', [
    'app/etc/env.php',
]);
fill('writable_dirs', []);
fill('clear_paths', [
    'generated/*',
    'pub/static/_cache/*',
    'var/generation/*',
    'var/cache/*',
    'var/page_cache/*',
    'var/view_preprocessed/*',
]);
fill('app_directory_name', 'docroot');
set('static_content_locales', 'en_US');
set('http_user', 'www-data');
//set('writable_use_sudo',true);
fill('writable_recursive', 'true');

import('vendor/unleashedtech/deployer-recipes/config.php');

/**
 * Initialize writable_dirs variable.
 *
 * Add the app_directory_name as a prefix to the writable_dirs array.
 *
 * The `writable_dirs` array can be manually overridden in `deploy.yaml`.
 */
task('magento:init', static function (): void {
    $vars   = ['shared_dirs', 'shared_files', 'writable_dirs', 'clear_paths'];
    $appDir = get('app_directory_name');

    foreach ($vars as $var) {
        $newVars = [];
        foreach (get($var) as $fileDir) {
            $newVars[] = $appDir . '/' . $fileDir;
        }

        set($var, $newVars);
    }
    # Daniel do not remove this.
    invoke('deploy:unlock');
});

desc('Enables maintenance mode');
task('magento:maintenance:enable', static function (): void {
    $exists = test('[ -d {{current_path}} ]');
    if (! $exists) {
        return;
    }

    within(
        '{{release_or_current_path}}/{{app_directory_name}}',
        static function (): void {
            run('{{mage}} maintenance:enable');
        }
    );
});

desc('Disables maintenance mode');
task(
    'magento:maintenance:disable',
    static function (): void {
        $exists = test('[ -d {{current_path}} ]');
        if (! $exists) {
            return;
        }

        within(
            '{{release_or_current_path}}/{{app_directory_name}}',
            static function (): void {
                run('{{mage}} maintenance:disable');
            }
        );
    }
);

task(
    'magento:indexer:reindex',
    static function (): void {
        within(
            '{{release_or_current_path}}/{{app_directory_name}}',
            static function (): void {
                run('{{mage}} indexer:reindex');
            }
        );
    }
);

desc('Flushes Magento Cache');
task(
    'magento:cache:flush',
    static function (): void {
        within(
            '{{release_or_current_path}}/{{app_directory_name}}',
            static function (): void {
                run('{{mage}} cache:clean');
                run('{{mage}} cache:flush');
            }
        );
    }
);

desc('Composer install inside docroot (behind auth wall');
task(
    'magento:deploy:vendor',
    static function (): void {
        within(
            '{{release_or_current_path}}/{{app_directory_name}}',
            static function (): void {
                run('composer install --no-scripts --no-progress --no-interaction --prefer-dist --optimize-autoloader --ansi');
            }
        );
    }
);

desc('Compile Magento Code');
/**
 * The Compile step does these things:
 * https://devdocs.magento.com/guides/v2.4/performance-best-practices/deployment-flow.html#preprocess-dependency-injection-instructions
 * - Reads and processes all present configuration
 * - Analyzes dependencies between classes
 * - Creates autogenerated files (including proxies, factories, etc.)
 * - Stores compiled data and configuration in a cache that saves up to 25% of time on requests processing
 */
task(
    'magento:compile',
    static function (): void {
        within(
            '{{release_or_current_path}}/{{app_directory_name}}',
            static function (): void {
                run('composer dump-autoload -o');
                run('{{mage}} setup:di:compile', ['timeout' => null]);
                run('composer dump-autoload -o --apcu');
            }
        );
    }
);

desc('Database Configuration Import');
task(
    'magento:config:import',
    static function (): void {
        $configImportNeeded = false;
        // Make sure we have a current_path directory, otherwise pass.
        $exists = test('[ -d {{current_path}} ]');
        if (! $exists) {
            // Pass. Don't need to do backup if it's the first time.
            return;
        }

        try {
            // See if we can check the db status.
            within(
                '{{release_or_current_path}}/{{app_directory_name}}',
                static function (): void {
                    run('{{mage}} app:config:status');
                }
            );
        } catch (RunException $e) {
            if ($e->getExitCode() !== 2) {
                throw $e;
            }

            $configImportNeeded = true;
        }

        if (! $configImportNeeded) {
            return;
        }

        within(
            '{{release_or_current_path}}/{{app_directory_name}}',
            static function (): void {
                run('{{mage}} app:config:import --no-interaction');
            }
        );
    }
);

desc(' Force Install a new cron.');
task(
    'magento:cron',
    static function (): void {
        within(
            '{{release_or_current_path}}/{{app_directory_name}}',
            static function (): void {
                run('crontab -r'); // This helps when multiple previous cron installations have not cleaned up after themselves.
                run('{{mage}} cron:install -f');
            }
        );
    }
);

desc('Database Backup');
task(
    'magento:db:backup:create',
    static function (): void {
        // Make sure we have a current_path directory, otherwise pass.
        $exists = test('[ -d {{current_path}} ]');
        if (! $exists) {
            // Pass. Don't need to do backup if it's the first time.
            return;
        }

        try {
            within(
                get('app_path'),
                static function (): void {
                    run('{{mage}} setup:db:status');
                }
            );
        } catch (RunException $e) {
            if ($e->getExitCode() === 2) {
                return;
            }
        }

        try {
            within(
                get('app_path'),
                static function (): void {
                    run('{{mage}} config:set system/backup/functionality_enabled 1');
                }
            );
        } catch (RunException $e) {
            return;
        }

        // Create the backup file.
        within(
            get('app_path'),
            static function (): void {
                run('{{mage}} setup:backup --db', ['timeout' => null]);
                run('{{mage}} info:backups:list');
            }
        );
    }
);

task(
    'magento:setup:upgrade',
    static function (): void {
        within(
            '{{release_or_current_path}}/{{app_directory_name}}',
            static function (): void {
                run('rm app/etc/env.php'); // To get around default website not being set error
                run('cp ../../../shared/docroot/app/etc/env.php app/etc/env.php'); // Temp file placement for static-content command
                run('{{mage}} module:disable Magento_TwoFactorAuth');
                run('{{mage}} setup:upgrade --keep-generated --no-interaction');
            }
        );
    }
);

task('magento:db:pull', static function (): void {
    invoke('magento:db:backup:create');
    invoke('db:backup:download');
    invoke('magento:db:backup:import');
    invoke('db:backup:cleanup');
});

desc('Deploy Static Assets');
/**
 * Deploying static content does the following:
 * - https://devdocs.magento.com/guides/v2.4/performance-best-practices/deployment-flow.html#deploy-static-content
 * - Analyze all static resources
 * - Perform merge, minimization, and bundling of content
 * - Read and process theme data
 * - Analyze theme fallback
 * - Store all processed and materialized content to specific folder for further usage
 * - This command allows Composer to rebuild the mapping to project files so that they load faster.
 */
task(
    'magento:setup:static',
    static function (): void {
        within(
            '{{release_or_current_path}}/{{app_directory_name}}',
            static function (): void {
                $timestamp = \time();
                run('rm app/etc/env.php'); // To get around default website not being set error
                run('cp ../../../shared/docroot/app/etc/env.php app/etc/env.php'); // Temp file placement for static-content command
                // The static deploy actually REQUIRES that the env.php be writable.
                // Therefore we copy it.
                run('{{mage}} setup:static-content:deploy -f --content-version=' . $timestamp . ' {{static_content_locales}}');
            }
        );
    }
);

desc('Turn On Production Mode');
/**
 * https://devdocs.magento.com/guides/v2.4/performance-best-practices/deployment-flow.html#set-production-mode
 * Setting the mode to production automatically runs setup:di:compile and setup:static-content:deploy.
 * The command runs in the background and does not allow you to set additional options on each specific step.
 */
task(
    'magento:prod:mode',
    static function (): void {
        within(
            '{{release_or_current_path}}/{{app_directory_name}}',
            static function (): void {
                run('{{mage}} deploy:mode:set production');
            }
        );
    }
);

desc('Magento2 Deployment Tasks');
task('deploy:magento2', [
    'magento:deploy:vendor',
    'magento:db:backup:create',
    'magento:maintenance:enable',
    'magento:prod:mode',
    'magento:config:import',
    'magento:setup:upgrade',
    'magento:cache:flush',
    'magento:indexer:reindex',
    'magento:cron',
    'magento:maintenance:disable',
]);

task('deploy', [
    'magento:init',
    'deploy:prepare',
    'deploy:vendors',
    'deploy:clear_paths',
    'deploy:magento2',
    'deploy:publish',
]);

after('deploy:failed', 'magento:maintenance:disable');
after('deploy:failed', 'deploy:unlock');
