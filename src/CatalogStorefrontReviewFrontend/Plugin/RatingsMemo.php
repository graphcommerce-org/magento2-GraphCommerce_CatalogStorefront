<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReviewFrontend\Plugin;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Review\Block\Form;

/**
 * Remembers the rating collection the review form renders.
 *
 * The block builds a collection, loads it and loads its options again on every call, and the form
 * template asks seven times: once to decide whether to render the block, once for the count, once
 * per loop and again for the script that validates the answers. That is seventeen queries for one
 * list of ratings, on every page that carries the form.
 *
 * The collection is returned as it is, so a second caller reads the same loaded rows. Only reading
 * it is what the template does.
 */
class RatingsMemo implements ResetAfterRequestInterface
{
    /**
     * A frontend request renders one store view, and the block resolves the store itself, so the
     * answer holds for the whole request.
     *
     * @var mixed
     */
    private $ratings = null;

    /**
     * @param Form $subject
     * @param \Closure $proceed
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundGetRatings(Form $subject, \Closure $proceed)
    {
        if ($this->ratings === null) {
            $this->ratings = $proceed();
        }

        return $this->ratings;
    }

    public function _resetState(): void
    {
        $this->ratings = null;
    }
}
