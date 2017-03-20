<?php

namespace Canvas\Console\Commands;

use Exception;
use Canvas\Models\User;
use Canvas\Meta\Constants;
use Canvas\Helpers\SetupHelper;
use Canvas\Helpers\ConfigHelper;
use Canvas\Extensions\ThemeManager;
use Illuminate\Support\Facades\Artisan;

class Install extends CanvasCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'canvas:install {--views : Also publish Canvas views.} {--f|force : Overwrite existing files.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Canvas install wizard';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $missingExtensions = [];
        $this->comment(PHP_EOL.'Checking server requirements...');
        foreach (Constants::REQUIRED_EXTENSIONS as $extension) {
            if (in_array($extension, get_loaded_extensions())) {
                continue;
            } else {
                array_push($missingExtensions, $extension);
            }
        }

        if (! empty($missingExtensions)) {
            $this->line(PHP_EOL.'<error>[✘]</error> Please install the following PHP extensions before continuing: '
                .explode(', ', $missingExtensions));
            die();
        }

        // If the canvas_installed.lock file is found in the storage/ directory
        // then Canvas has already been installed and there is no need for
        // the installer wizard to continue.
        if (file_exists(storage_path(Constants::INSTALLED_FILE))) {
            $this->line('<info>✔</info> Canvas has already been installed.');
        } else {
            $config = ConfigHelper::getWriter();

            // Gather the options...
            $force = $this->option('force') ?: false;
            $withViews = $this->option('views') ?: false;

//            $this->comment(PHP_EOL.'Welcome to the Canvas Install Wizard! You\'ll be up and running in no time...');

            // Attempt to link storage/app/public folder to public/storage;
            // This won't work on an OS without symlink support (e.g. Windows)
            try {
                Artisan::call('storage:link');
            } catch (Exception $e) {
                $this->line(PHP_EOL.'Could not link <info>storage/app/public</info> folder to <info>public/storage</info>:');
                $this->line("<error>[✘]</error> {$e->getMessage()}");
            }

            try {
                // Publish config files...
                Artisan::call('canvas:publish:config', [
                    '--y' => true,
                    '--force' => true,
                ]);
                // Publish public assets...
                Artisan::call('canvas:publish:assets', [
                    '--y' => true,
                    '--force' => true,
                ]);
                // Publish view files...
                if ($withViews) {
                    Artisan::call('canvas:publish:views', [
                        '--y' => true,
                        '--force' => $force,
                    ]);
                }

                // Set up the database...
                if (! (SetupHelper::requiredTablesExists())) {
                    $this->comment('Configuring the database...');
                    $exitCode = Artisan::call('migrate', []);
                    $exitCode = Artisan::call('db:seed', [
                        '--class' => 'Canvas\DatabaseSeeder',
                    ]);
                }

                // Admin user information...
                $this->comment(PHP_EOL.'<info>Step 1/3: Admin user account</info>');
                $email = $this->ask('Email');
                $password = $this->secret('Password');
                $firstName = $this->ask('First name');
                $lastName = $this->ask('Last name');
                $this->createUser($email, $password, $firstName, $lastName);
                $this->line('<info>[✔]</info> Success! Your admin user account has been created.');

                // Blog title...
                $blogTitle = $this->ask('Step 2/3: Blog title');
                $this->title($blogTitle);
                $this->line('<info>[✔]</info> Success! The title of the blog has been saved.');

                // Blog subtitle...
                $blogSubtitle = $this->ask('Step 3/3: Blog subtitle');
                $this->subtitle($blogSubtitle);
                $this->line('<info>[✔]</info> Success! The subtitle of the blog has been saved.');

                // Default blog description...
                $this->description('Not just another blog...');

                // Default blog SEO tags...
                $this->seo('simple,powerful,blog,publishing,platform');

                // Default blog posts per page...
                $this->postsPerPage(6, $config);

                // Build the search index...
                $this->rebuildSearchIndexes();

                // Generate a unique application key...
                $this->comment('Creating a unique application key...');
                $exitCode = Artisan::call('key:generate');

                // Additional blog settings...
                $this->comment('Finishing up the installation process...');
                $this->disqus();
                $this->googleAnalytics();
                $this->twitterCardType();
                $this->canvasVersion();
                $this->installed();
                $this->socialHeaderIcons();

                // Clear the caches...
                Artisan::call('config:clear');
                Artisan::call('cache:clear');
                Artisan::call('view:clear');
                Artisan::call('route:clear');

                $this->line(PHP_EOL.'<info>[✔]</info> Canvas was installed successfully!'.PHP_EOL);

                // Display installation info...
                $headers = ['Login Email', 'Login Password', 'Version', 'Theme'];
                $data = User::select('email', 'password')->get()->toArray();

                $themeManager = new ThemeManager(resolve('app'), resolve('files'));
                $activeTheme = $themeManager->getTheme($themeManager->getActiveTheme()) ?: $themeManager->getDefaultTheme();
                $data[0]['password'] = 'Your chosen password.';
                array_push($data[0], 'Canvas'.' '.$this->canvasVersion(), $activeTheme->getName().' '.$activeTheme->getVersion());
                $this->table($headers, $data);

                $config->save();
            } catch (Exception $e) {
                // Rollback migrations
                // Artisan::call('migrate:rollback');
                // Remove install file
                $this->uninstalled();
                // Display message
                $this->line(PHP_EOL.'<error>An unexpected error occurred. Installation could not continue.</error>');
                $this->error("[✘] {$e->getMessage()}");
                $this->comment(PHP_EOL.'Please run the installer again.');
                $this->line(PHP_EOL.'If this error persists please consult https://github.com/cnvs/easel/issues.'.PHP_EOL);
            }
        }
    }
}
