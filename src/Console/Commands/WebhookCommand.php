<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Illuminate\Console\Command;
use OpenFGA\Laravel\Webhooks\WebhookManager;

class WebhookCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openfga:webhook
                            {action : The action to perform (list, test, enable, disable)}
                            {name? : The webhook name (for enable/disable actions)}
                            {--url= : Test webhook URL}
                            {--event=permission.granted : Event type for testing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage OpenFGA webhooks';

    /**
     * Execute the console command.
     */
    public function handle(WebhookManager $webhookManager): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'list' => $this->listWebhooks($webhookManager),
            'test' => $this->testWebhook($webhookManager),
            'enable' => $this->enableWebhook($webhookManager),
            'disable' => $this->disableWebhook($webhookManager),
            default => $this->invalidAction($action),
        };
    }

    /**
     * List configured webhooks
     */
    private function listWebhooks(WebhookManager $webhookManager): int
    {
        $webhooks = $webhookManager->getWebhooks();

        if (empty($webhooks)) {
            $this->info('No webhooks configured.');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($webhooks as $name => $webhook) {
            $rows[] = [
                $name,
                $webhook['url'],
                implode(', ', $webhook['events'] ?? ['*']),
                $webhook['active'] ? 'Active' : 'Inactive',
            ];
        }

        $this->table(['Name', 'URL', 'Events', 'Status'], $rows);

        return self::SUCCESS;
    }

    /**
     * Test a webhook
     */
    private function testWebhook(WebhookManager $webhookManager): int
    {
        $url = $this->option('url');
        $event = $this->option('event');

        if (! $url) {
            $this->error('Please provide a webhook URL using --url option');
            return self::FAILURE;
        }

        $this->info("Testing webhook: {$url}");
        $this->comment("Sending test event: {$event}");

        // Create a test event
        $testEvent = new \OpenFGA\Laravel\Events\PermissionChanged(
            user: 'user:test',
            relation: 'viewer',
            object: 'document:test',
            action: 'granted',
            metadata: ['test' => true, 'timestamp' => now()->toIso8601String()]
        );

        // Register temporary webhook
        $webhookManager->register('test', $url, [$event]);

        try {
            // Send the webhook
            $webhookManager->notifyPermissionChange($testEvent);
            
            $this->info('✅ Webhook test completed. Check your endpoint logs.');
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Webhook test failed: {$e->getMessage()}");
            return self::FAILURE;
        } finally {
            // Clean up
            $webhookManager->unregister('test');
        }
    }

    /**
     * Enable a webhook
     */
    private function enableWebhook(WebhookManager $webhookManager): int
    {
        $name = $this->argument('name');

        if (! $name) {
            $this->error('Please provide a webhook name');
            return self::FAILURE;
        }

        $webhookManager->enable($name);
        $this->info("Webhook '{$name}' has been enabled.");

        return self::SUCCESS;
    }

    /**
     * Disable a webhook
     */
    private function disableWebhook(WebhookManager $webhookManager): int
    {
        $name = $this->argument('name');

        if (! $name) {
            $this->error('Please provide a webhook name');
            return self::FAILURE;
        }

        $webhookManager->disable($name);
        $this->info("Webhook '{$name}' has been disabled.");

        return self::SUCCESS;
    }

    /**
     * Handle invalid action
     */
    private function invalidAction(string $action): int
    {
        $this->error("Invalid action: {$action}");
        $this->comment('Valid actions are: list, test, enable, disable');
        return self::FAILURE;
    }
}