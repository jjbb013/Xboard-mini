<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Utils\Helper;

class XboardCreateAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'xboard:create-admin';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new admin user from environment variables';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (!$email || !$password) {
            $this->error('ADMIN_EMAIL and ADMIN_PASSWORD environment variables are required.');
            return 1;
        }

        if (User::where('email', $email)->exists()) {
            $this->info('Admin user with this email already exists.');
            return 0;
        }

        $user = new User();
        $user->email = $email;
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        $user->is_admin = 1;
        
        if ($user->save()) {
            $this->info('Admin user created successfully.');
        } else {
            $this->error('Failed to create admin user.');
            return 1;
        }

        return 0;
    }
}
