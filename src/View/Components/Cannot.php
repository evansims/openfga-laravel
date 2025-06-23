<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\View\Components;

use Illuminate\Contracts\View\View;

/**
 * Blade component for rendering content when user does NOT have OpenFGA permissions.
 */
class Cannot extends Can
{
    /**
     * Get the view / contents that represent the component.
     *
     * @return \Illuminate\Contracts\View\View|string
     */
    public function render(): View|string
    {
        if ($this->hasPermission()) {
            return '';
        }

        return view('openfga::components.cannot');
    }
}