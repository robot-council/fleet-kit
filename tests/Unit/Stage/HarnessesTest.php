<?php

declare(strict_types=1);

use FleetKit\Pace\ConfigError;
use FleetKit\Stage\Harnesses;

it('lists the stageable harnesses from the table', function () {
    expect(Harnesses::names())->toBe(['claude', 'codex']);
});

it('records what Claude Code can do', function () {
    $claude = Harnesses::get('claude');

    expect($claude->executable)->toBe('claude')
        ->and($claude->arguments('P', null))->toBe(['P'])
        ->and($claude->arguments('P', 'c-1'))->toBe(['P', '--resume', 'c-1'])
        ->and($claude->remoteControlFlag)->toBe('--remote-control')
        ->and($claude->channelFlag)->toBe('--dangerously-load-development-channels')
        ->and($claude->confirmChannels)->toBeTrue()
        ->and([$claude->conversations, $claude->holders, $claude->collect, $claude->mcp])->toBe(['claude-projects', 'claude-sessions', true, 'claude-mcp-list'])
        ->and([$claude->wake, $claude->usage])->toBe(['channel', 'oauth-endpoint']);
});

it('records what Codex can do, from the measurements on fleet-kit#6', function () {
    $codex = Harnesses::get('codex');

    expect($codex->executable)->toBe('codex')
        ->and($codex->arguments('P', null))->toBe(['P'])
        ->and($codex->arguments('P', 'c-1'))->toBe(['resume', 'c-1', 'P'])
        ->and($codex->remoteControlFlag)->toBeNull()
        ->and($codex->channelFlag)->toBeNull()
        ->and($codex->confirmChannels)->toBeFalse()
        ->and([$codex->conversations, $codex->holders, $codex->collect, $codex->mcp])->toBe(['codex-rollouts', 'none', false, 'codex-mcp-list-json'])
        ->and([$codex->wake, $codex->usage])->toBe(['codex-queue', 'rollout-token-count']);
});

it('refuses a harness it does not list', function () {
    expect(fn () => Harnesses::get('gemini'))->toThrow(ConfigError::class, 'not in harnesses.toml');
});

const HARNESS_ROW = <<<'TOML'
    [x]
    executable = "x"
    launch = ["{prompt}"]
    resume = ["{prompt}", "{conversation}"]
    confirm_channels = false
    conversations = "claude-projects"
    holders = "none"
    collect = false
    mcp = "claude-mcp-list"
    wake = "none"
    usage = "none"
    TOML;

it('reads a well-formed row, and refuses a malformed one naming the key', function (string $from, string $to, string $message) {
    expect(Harnesses::parse(HARNESS_ROW))->toHaveKey('x');
    expect(fn () => Harnesses::parse(str_replace($from, $to, HARNESS_ROW)))->toThrow(ConfigError::class, $message);
})->with([
    'unknown placeholder' => ['launch = ["{prompt}"]', 'launch = ["{prompt}", "{shell}"]', 'only {prompt} and {conversation} exist'],
    'launch without a prompt' => ['launch = ["{prompt}"]', 'launch = ["go"]', 'launch must hold {prompt}'],
    'resume without a conversation' => ['resume = ["{prompt}", "{conversation}"]', 'resume = ["{prompt}"]', 'resume must hold {conversation}'],
    'unknown conversations' => ['conversations = "claude-projects"', 'conversations = "elsewhere"', 'conversations must be one of'],
    'unknown holders' => ['holders = "none"', 'holders = "sometimes"', 'holders must be one of'],
    'missing flag type' => ['collect = false', 'collect = "no"', 'collect must be true or false'],
    'executable with a space' => ['executable = "x"', 'executable = "x y"', 'executable is required'],
]);

it('refuses a remote-control flag that is not a long flag', function () {
    expect(fn () => Harnesses::parse(HARNESS_ROW."\nremote_control_flag = \"rm -rf\""))->toThrow(ConfigError::class, 'remote_control_flag must be a long flag');
});
