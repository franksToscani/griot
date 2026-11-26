<?php

namespace App\Console\Commands;

use App\Models\Provider;
use App\Profile\SyncAccountName;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Once, for the accounts saved before "one name" (docs/provider-first-steps.md): a provider has one
 * name, the business one (providers.name), and the account's name (users.name) follows it. The
 * business name always wins. Without --force it only lists what it would change. The work is
 * SyncAccountName's.
 *
 * The output says, for each provider whose account has another name, its slug (or id-N) and the
 * word "diverso", then the totals: never a name or an email. Run again, it changes nothing.
 */
#[Signature('providers:sync-account-names
    {--force : Copy the business name to the account; without it, only list}')]
#[Description('Give each provider\'s account the name of the business (providers.name)')]
class SyncAccountNamesCommand extends Command
{
    /**
     * Execute the console command. It always succeeds: a provider without an account (the foreign
     * key forbids it) is skipped and counted apart.
     */
    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $result = SyncAccountName::all(Provider::query()->with('user')->orderBy('id')->lazy(), $force);

        foreach ($result['different'] as $provider) {
            $this->line(__('console.sync_names.line', ['provider' => $provider]));
        }

        $this->info(__('console.sync_names.summary', [
            'checked' => $result['checked'],
            'different' => count($result['different']),
            'updated' => $result['updated'],
            'without' => $result['without'],
        ]));

        if (! $force && $result['different'] !== []) {
            $this->comment(__('console.sync_names.dry_run'));
        }

        return self::SUCCESS;
    }
}
