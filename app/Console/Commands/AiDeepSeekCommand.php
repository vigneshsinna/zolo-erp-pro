<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\Agents\DeepSeekAgent;
use Illuminate\Console\Command;
use Throwable;

class AiDeepSeekCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ai:deepseek
                            {--prompt= : The text prompt to send to DeepSeek}
                            {--image= : Optional base64 data URI, URL, or file path to an image for visual reasoning}
                            {--model= : Model key (default: deepseek-ai/deepseek-v4.1-flash)}
                            {--provider= : Provider key (default: nvidia)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Invoke DeepSeek AI agent via NVIDIA NIM for text or multimodal visual reasoning.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $prompt = (string) ($this->option('prompt') ?: 'Describe the path in this image and the landscape around it in two sentences.');
        $image = (string) $this->option('image');
        $provider = (string) ($this->option('provider') ?: 'nvidia');
        $model = (string) ($this->option('model') ?: DeepSeekAgent::MODEL);

        $apiKey = config("ai.providers.{$provider}.key", env('NVIDIA_API_KEY'));
        $baseUrl = config("ai.providers.{$provider}.url", env('NVIDIA_BASE_URL', DeepSeekAgent::DEFAULT_BASE_URL));

        $this->info('DeepSeek Agent Client Configuration:');
        $this->line("  Provider: {$provider}");
        $this->line("  Base URL: {$baseUrl}");
        $this->line("  Model:    {$model}");
        $this->line("  API Key:  " . (blank($apiKey) ? '<NOT SET (Set NVIDIA_API_KEY in .env)>' : '***' . substr((string) $apiKey, -4)));
        $this->newLine();

        if (blank($apiKey)) {
            $this->warn('Warning: NVIDIA_API_KEY is not set in environment or config.');
            $this->comment('Add NVIDIA_API_KEY=your_key to your .env file or pass via environment.');
            return self::FAILURE;
        }

        $agent = new DeepSeekAgent();
        $this->info("Sending prompt to DeepSeek ({$model})...");
        $this->line("Prompt: \"{$prompt}\"");

        try {
            $startTime = microtime(true);

            if (filled($image)) {
                $this->line('Image attachment detected. Resolving multimodal image payload...');
                $response = $agent->askWithImage($prompt, $image);
            } else {
                $response = $agent->prompt($prompt, provider: $provider, model: $model);
            }

            $duration = round(microtime(true) - $startTime, 2);

            $this->newLine();
            $this->info("Response received in {$duration}s:");
            $this->line($response->text);
            $this->newLine();

            if (isset($response->usage)) {
                $this->comment("Token usage: Prompt: {$response->usage->promptTokens}, Completion: {$response->usage->completionTokens}");
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('DeepSeek request failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
