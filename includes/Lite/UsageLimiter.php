<?php
/**
 * Simple conversation counter for Lite.
 *
 * Replaces the complex UsageTracker from the main plugin.
 * Uses wp_options for counting.
 *
 * @package GspltdChatLite\Lite
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

namespace GspltdChatLite\Lite;

/**
 * Usage Limiter — 50 conversations/month enforcement.
 *
 * SOLID: Single Responsibility — only conversation counting.
 */
class UsageLimiter {

    private const OPTION_USED       = 'gcl_conversations_used';
    private const OPTION_RESET_DATE = 'gcl_conversations_reset_date';

    /**
     * Can a new conversation be started?
     *
     * @return bool
     */
    public function canStartConversation(): bool {
        $this->maybeResetMonthlyCounter();
        $used = (int) \get_option( self::OPTION_USED, 0 );
        return $used < LiteConfig::MONTHLY_LIMIT;
    }

    /**
     * Increment the conversation counter.
     *
     * @return int New count.
     */
    public function incrementUsage(): int {
        $this->maybeResetMonthlyCounter();
        $current = (int) \get_option( self::OPTION_USED, 0 );
        $new     = $current + 1;
        \update_option( self::OPTION_USED, $new );
        return $new;
    }

    /**
     * Get current usage stats.
     *
     * @return array{used: int, limit: int, remaining: int, reset_date: string}
     */
    public function getUsageStats(): array {
        $this->maybeResetMonthlyCounter();
        $used = (int) \get_option( self::OPTION_USED, 0 );

        return [
            'used'       => $used,
            'limit'      => LiteConfig::MONTHLY_LIMIT,
            'remaining'  => max( 0, LiteConfig::MONTHLY_LIMIT - $used ),
            'reset_date' => \get_option( self::OPTION_RESET_DATE, '' ),
        ];
    }

    /**
     * Reset counter on first day of new month.
     */
    private function maybeResetMonthlyCounter(): void {
        $reset_date          = \get_option( self::OPTION_RESET_DATE, '' );
        $current_month_start = gmdate( 'Y-m-01' );

        if ( $reset_date !== $current_month_start ) {
            \update_option( self::OPTION_USED, 0 );
            \update_option( self::OPTION_RESET_DATE, $current_month_start );
        }
    }
}
