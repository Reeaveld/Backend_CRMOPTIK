<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdminUser extends Command
{
    protected $signature = 'crm:create-admin {--email= : Email akun admin} {--name= : Nama admin}';
    protected $description = 'Buat atau reset akun admin secara interaktif dan aman untuk produksi';

    public function handle(): int
    {
        $this->info('=== Setup Akun Admin OptikCRM ===');

        $email = $this->option('email') ?: $this->ask('Masukkan Email Admin (contoh: admin@optikcrm.com)');
        $name = $this->option('name') ?: $this->ask('Masukkan Nama Admin', 'Admin Optik');

        $validator = Validator::make(['email' => $email], [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            $this->error('Format email tidak valid!');
            return self::FAILURE;
        }

        $password = $this->secret('Masukkan Password Admin (minimal 8 karakter)');
        if (strlen($password) < 8) {
            $this->error('Password minimal 8 karakter!');
            return self::FAILURE;
        }

        $passwordConfirm = $this->secret('Konfirmasi Password Admin');
        if ($password !== $passwordConfirm) {
            $this->error('Konfirmasi password tidak cocok!');
            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name'     => $name,
                'password' => Hash::make($password),
            ]
        );

        $this->info("✅ Akun admin berhasil disimpan! Email: {$user->email}");
        return self::SUCCESS;
    }
}
