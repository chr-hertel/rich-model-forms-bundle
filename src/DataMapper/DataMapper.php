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

namespace SensioLabs\RichModelForms\DataMapper;

use SensioLabs\RichModelForms\DataMapper\ExceptionHandler\ArgumentTypeMismatchExceptionHandler;
use SensioLabs\RichModelForms\DataMapper\ExceptionHandler\ChainExceptionHandler;
use SensioLabs\RichModelForms\DataMapper\ExceptionHandler\ExceptionHandlerRegistry;
use SensioLabs\RichModelForms\DataMapper\ExceptionHandler\GenericExceptionHandler;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Exception\UnexpectedTypeException;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Translation\TranslatorInterface;

/**
 * @author Christian Flothmann <christian.flothmann@sensiolabs.de>
 */
final class DataMapper implements DataMapperInterface
{
    private $dataMapper;
    private $propertyAccessor;
    private $exceptionHandlerRegistry;
    private $translator;
    private $translationDomain;

    public function __construct(DataMapperInterface $dataMapper, PropertyAccessorInterface $propertyAccessor, ExceptionHandlerRegistry $exceptionHandlerRegistry, TranslatorInterface $translator = null, string $translationDomain = null)
    {
        $this->dataMapper = $dataMapper;
        $this->propertyAccessor = $propertyAccessor;
        $this->exceptionHandlerRegistry = $exceptionHandlerRegistry;
        $this->translator = $translator;
        $this->translationDomain = $translationDomain;
    }

    public function mapDataToForms($data, $forms): void
    {
        $isDataEmpty = null === $data || [] === $data;

        if (!$isDataEmpty && !\is_array($data) && !\is_object($data)) {
            throw new UnexpectedTypeException($data, 'object, array or null');
        }

        $formsToBeMapped = [];

        foreach ($forms as $form) {
            $readPropertyPath = $form->getConfig()->getOption('read_property_path');

            if (!$isDataEmpty && $readPropertyPath instanceof \Closure && $form->getConfig()->getMapped()) {
                $form->setData($readPropertyPath($data));
            } elseif (!$isDataEmpty && null !== $readPropertyPath && $form->getConfig()->getMapped()) {
                $form->setData($this->propertyAccessor->getValue($data, $readPropertyPath));
            } elseif (null !== $readPropertyPath) {
                $form->setData($form->getConfig()->getData());
            } else {
                $formsToBeMapped[] = $form;
            }
        }

        $this->dataMapper->mapDataToForms($data, $formsToBeMapped);
    }

    public function mapFormsToData($forms, &$data): void
    {
        if (null === $data) {
            return;
        }

        if (!\is_array($data) && !\is_object($data)) {
            throw new UnexpectedTypeException($data, 'object, array or null');
        }

        foreach ($forms as $form) {
            $forwardToWrappedDataMapper = false;
            $config = $form->getConfig();

            $readPropertyPath = $config->getOption('read_property_path');

            if (null === $writePropertyPath = $config->getOption('write_property_path')) {
                $forwardToWrappedDataMapper = true;
            } elseif (!$config->getMapped() || !$form->isSubmitted() || !$form->isSynchronized() || $form->isDisabled()) {
                // write-back is disabled if the form is not synchronized (transformation failed),
                // if the form was not submitted and if the form is disabled (modification not allowed)
                $forwardToWrappedDataMapper = true;
            } elseif (\is_object($data) && $config->getByReference() && $form->getData() === ($readPropertyPath instanceof \Closure ? $readPropertyPath($data) : $this->propertyAccessor->getValue($data, $readPropertyPath)) && !$writePropertyPath instanceof \Closure) {
                $forwardToWrappedDataMapper = true;
            }

            try {
                if ($forwardToWrappedDataMapper) {
                    $this->dataMapper->mapFormsToData([$form], $data);
                } elseif ($writePropertyPath instanceof \Closure) {
                    $writePropertyPath($data, $form->getData());
                } else {
                    $this->propertyAccessor->setValue($data, $writePropertyPath, $form->getData());
                }
            } catch (\Throwable $e) {
                $exceptionHandlers = [];

                if (null !== $form->getConfig()->getOption('expected_exception')) {
                    foreach ($form->getConfig()->getOption('expected_exception') as $exceptionClass) {
                        $exceptionHandlers[] = new GenericExceptionHandler($exceptionClass);
                    }

                    $exceptionHandlers[] = new ArgumentTypeMismatchExceptionHandler($this->translator, $this->translationDomain);
                } else {
                    foreach ($form->getConfig()->getOption('exception_handling_strategy') as $strategy) {
                        $exceptionHandlers[] = $this->exceptionHandlerRegistry->get($strategy);
                    }
                }

                if (1 === \count($exceptionHandlers)) {
                    $exceptionHandler = reset($exceptionHandlers);
                } else {
                    $exceptionHandler = new ChainExceptionHandler($exceptionHandlers);
                }

                if (null !== $error = $exceptionHandler->getError($form, $data, $e)) {
                    $form->addError($error);
                } else {
                    throw $e;
                }
            }
        }
    }
}
