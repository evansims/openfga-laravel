<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use OpenFGA\Laravel\Events\PermissionChanged;
use OpenFGA\Laravel\Webhooks\WebhookManager;

use function sprintf;

final class WebhookCommand extends Command
{
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage OpenFGA webhooks';

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
     * Execute the console command.
     *
     * @param WebhookManager $webhookManager
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
     * Disable a webhook.
     *
     * @param WebhookManager $webhookManager
     */
    private function disableWebhook(WebhookManager $webhookManager): int
    {
        $name = $this->argument('name');

        if (! $name) {
            $this->error('Please provide a webhook name');

            return self::FAILURE;
        }

        $webhookManager->disable($name);
        $this->info(sprintf("Webhook '%s' has been disabled.", $name));

        return self::SUCCESS;
    }

    /**
     * Enable a webhook.
     *
     * @param WebhookManager $webhookManager
     */
    private function enableWebhook(WebhookManager $webhookManager): int
    {
        $name = $this->argument('name');

        if (! $name) {
            $this->error('Please provide a webhook name');

            return self::FAILURE;
        }

        $webhookManager->enable($name);
        $this->info(sprintf("Webhook '%s' has been enabled.", $name));

        return self::SUCCESS;
    }

    /**
     * Handle invalid action.
     *
     * @param string $action
     */
    private function invalidAction(string $action): int
    {
        $this->error('Invalid action: ' . $action);
        $this->comment('Valid actions are: list, test, enable, disable');

        return self::FAILURE;
    }

    /**
     * List configured webhooks.
     *
     * @param WebhookManager $webhookManager
     */
    private function listWebhooks(WebhookManager $webhookManager): int
    {
        $webhooks = $webhookManager->getWebhooks();

        if ([] === $webhooks) {
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
     * Test a webhook.
     *
     * @param WebhookManager $webhookManager
     */
    private function testWebhook(WebhookManager $webhookManager): int
    {
        $url = $this->option('url');
        $event = $this->option('event');

        if (! $url) {
            $this->error('Please provide a webhook URL using --url option');

            return self::FAILURE;
        }

        $this->info('Testing webhook: ' . $url);
        $this->comment('Sending test event: ' . $event);

        // Create a test event
        $testEvent = new PermissionChanged(
            user: 'user:test',
            relation: 'viewer',
            object: 'document:test',
            action: 'granted',
            metadata: ['test' => true, 'timestamp' => now()->toIso8601String()],
        );

        // Register temporary webhook
        $webhookManager->register('test', $url, [$event]);

        try {
            // Send the webhook
            $webhookManager->notifyPermissionChange($testEvent);

            $this->info('✅ Webhook test completed. Check your endpoint logs.');

            return self::SUCCESS;
        } catch (Exception $exception) {
            $this->error('Webhook test failed: ' . $exception->getMessage());

            return self::FAILURE;
        } finally {
            // Clean up
            $webhookManager->unregister('test');
        }
    }
}
