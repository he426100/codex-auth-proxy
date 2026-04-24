<?php

declare(strict_types=1);

namespace CodexAuthProxy\Config;

use CodexAuthProxy\Codex\CodexProtocol;
use CodexAuthProxy\Codex\CodexRuntimeProfile;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class AppConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('codex_auth_proxy');
        $root = $treeBuilder->getRootNode();
        $root
            ->children()
                ->scalarNode('home')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('accounts_dir')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('state_file')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('host')->defaultValue('127.0.0.1')->cannotBeEmpty()->end()
                ->integerNode('port')->min(1)->max(65535)->defaultValue(1456)->end()
                ->integerNode('cooldown_seconds')->min(1)->defaultValue(18000)->end()
                ->scalarNode('callback_host')->defaultValue('localhost')->cannotBeEmpty()->end()
                ->integerNode('callback_port')->min(1)->max(65535)->defaultValue(1455)->end()
                ->integerNode('callback_timeout_seconds')->min(1)->defaultValue(300)->end()
                ->scalarNode('codex_user_agent')->defaultValue(CodexRuntimeProfile::defaultUserAgent())->end()
                ->scalarNode('codex_beta_features')->defaultValue(CodexRuntimeProfile::defaultBetaFeatures())->end()
                ->scalarNode('codex_originator')->defaultValue(CodexRuntimeProfile::defaultOriginator())->cannotBeEmpty()->end()
                ->scalarNode('codex_residency')->defaultValue(CodexRuntimeProfile::defaultResidency())->end()
                ->scalarNode('codex_upstream_base_url')->defaultValue(CodexProtocol::defaultUpstreamBaseUrl())->cannotBeEmpty()->end()
                ->scalarNode('usage_base_url')->defaultValue(CodexProtocol::defaultBackendBaseUrl())->cannotBeEmpty()->end()
                ->integerNode('usage_refresh_interval_seconds')->min(0)->defaultValue(600)->end()
                ->integerNode('active_session_window_seconds')->min(0)->defaultValue(21600)->end()
                ->booleanNode('trace_mutations')->defaultTrue()->end()
                ->booleanNode('trace_timings')->defaultFalse()->end()
                ->scalarNode('http_proxy')->defaultNull()->end()
                ->scalarNode('https_proxy')->defaultNull()->end()
                ->scalarNode('no_proxy')->defaultValue('localhost,127.0.0.1,::1')->end()
            ->end();

        return $treeBuilder;
    }
}
