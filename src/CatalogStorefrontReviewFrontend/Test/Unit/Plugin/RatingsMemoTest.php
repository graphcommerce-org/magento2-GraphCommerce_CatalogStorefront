<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReviewFrontend\Test\Unit\Plugin;

use GraphCommerce\CatalogStorefrontReviewFrontend\Plugin\RatingsMemo;
use Magento\Review\Block\Form;
use PHPUnit\Framework\TestCase;

class RatingsMemoTest extends TestCase
{
    private RatingsMemo $plugin;

    private Form $form;

    private int $loads = 0;

    protected function setUp(): void
    {
        $this->plugin = new RatingsMemo();
        $this->form = $this->createMock(Form::class);
    }

    /**
     * The block builds a collection, loads it and loads its options again on every call.
     */
    private function load(): \Closure
    {
        return function () {
            $this->loads++;

            return new \ArrayObject(['one star', 'two stars']);
        };
    }

    public function testTheRatingsAreLoadedOnce(): void
    {
        // The form template asks seven times: to decide whether to render, for the count, per loop
        // and again for the script that validates the answers.
        for ($ask = 0; $ask < 7; $ask++) {
            $this->plugin->aroundGetRatings($this->form, $this->load());
        }

        $this->assertSame(1, $this->loads);
    }

    public function testEveryCallerReadsTheSameRows(): void
    {
        $first = $this->plugin->aroundGetRatings($this->form, $this->load());
        $second = $this->plugin->aroundGetRatings($this->form, $this->load());

        $this->assertSame($first, $second);
    }

    public function testResetDropsTheRatings(): void
    {
        // The plugin outlives a request under an application server, and a rating saved in the
        // admin must reach the next one.
        $this->plugin->aroundGetRatings($this->form, $this->load());
        $this->plugin->_resetState();
        $this->plugin->aroundGetRatings($this->form, $this->load());

        $this->assertSame(2, $this->loads);
    }
}
