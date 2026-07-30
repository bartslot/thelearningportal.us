<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Llm\LlmProfile;
use App\Services\Llm\LlmRouter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ask every configured provider two small questions and report what came back.
 *
 * Before trusting a model with a lesson, you want to know three things: does the
 * key work, does it answer in the language we asked for, and does it return JSON
 * when a job needs JSON (several free models happily ignore response_format).
 * This answers all three for a fraction of a cent.
 */
class ProbeLlmProviders extends Command
{
    protected $signature = 'llm:probe
        {provider?* : Providers to probe (default: every configured one)}
        {--json-only : Skip the prose check and only test JSON mode}';

    protected $description = 'Check which language-model providers are reachable, and whether they can answer in Dutch and return JSON';

    public function handle(LlmRouter $router): int
    {
        $names = $this->argument('provider') ?: array_keys((array) config('llm.providers', []));

        $this->newLine();
        $this->line('  <fg=gray>Roles:</> '.collect((array) config('llm.roles', []))
            ->map(fn ($ref, $role) => "{$role} → {$ref}")->implode('   '));
        $this->newLine();

        $rows = [];
        foreach ($names as $name) {
            try {
                $profile = $router->profile((string) $name);
            } catch (Throwable $e) {
                $rows[] = [$name, '—', '<fg=red>config</>', '—', substr($e->getMessage(), 0, 48)];

                continue;
            }

            if (! $profile->isUsable()) {
                $rows[] = [$profile->label, $profile->model, '<fg=yellow>no key</>', '—', 'set its API key in .env'];

                continue;
            }

            $rows[] = $this->probe($router, $profile);
        }

        $this->table(['Provider', 'Model', 'Reachable', 'JSON', 'Notes'], $rows);
        $this->newLine();

        return self::SUCCESS;
    }

    /** @return array{0:string,1:string,2:string,3:string,4:string} */
    private function probe(LlmRouter $router, LlmProfile $profile): array
    {
        $client = $router->build($profile);
        $reachable = '—';
        $note = '';
        $started = microtime(true);

        if (! $this->option('json-only')) {
            try {
                // Dutch on purpose: the lessons are Dutch, and a model that quietly
                // answers in English here will quietly do it in a scene too.
                $answer = $client->text(
                    'Antwoord in het Nederlands, in precies één korte zin.',
                    'Waarom voeren schepen in de 17e eeuw om Kaap de Goede Hoop?',
                    maxTokens: 120,
                );
                $ms = (int) ((microtime(true) - $started) * 1000);
                $dutch = (bool) preg_match('/\b(de|het|een|om|naar|schepen|voeren)\b/iu', $answer);
                $reachable = $dutch ? "<fg=green>yes</> {$ms}ms" : "<fg=yellow>yes</> {$ms}ms";
                $note = $dutch ? trim(mb_substr($answer, 0, 44)) : 'answered, but not in Dutch';
            } catch (Throwable $e) {
                return [$profile->label, $profile->model, '<fg=red>no</>', '—', substr($e->getMessage(), 0, 48)];
            }
        }

        $json = '<fg=red>no</>';
        try {
            $result = $client->json(
                'Return only JSON.',
                'Give me {"ok": true} and nothing else.',
                maxTokens: 60,
            );
            $json = ($result['ok'] ?? null) !== null ? '<fg=green>yes</>' : '<fg=yellow>shape?</>';
        } catch (Throwable $e) {
            $note = $note ?: substr($e->getMessage(), 0, 48);
        }

        return [$profile->label, $profile->model, $reachable, $json, $note];
    }
}
