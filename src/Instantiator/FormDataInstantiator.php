<?php

/*
 * This file is part of the RichModelFormsBundle package.
 *
 * (c) Christian Flothmann <christian.flothmann@sensiolabs.de>
 * (c) Christopher Hertel <christopher.hertel@sensiolabs.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SensioLabs\RichModelForms\Instantiator;

use Symfony\Component\Form\FormInterface;

/**
 * @author Christian Flothmann <christian.flothmann@sensiolabs.de>
 */
class FormDataInstantiator extends ObjectInstantiator
{
    private $form;

    public function __construct($factory, FormInterface $form)
    {
        parent::__construct($factory);

        $this->form = $form;
    }

    protected function isCompoundForm(): bool
    {
        return $this->form->getConfig()->getCompound();
    }

    protected function getData()
    {
        return $this->form->getData();
    }

    protected function getArgumentData(string $argument)
    {
        return $this->form->get($argument)->getData();
    }
}
