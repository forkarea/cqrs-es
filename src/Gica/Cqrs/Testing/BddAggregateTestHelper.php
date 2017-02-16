<?php
/******************************************************************************
 * Copyright (c) 2016 Constantin Galbenu <gica.galbenu@gmail.com>             *
 ******************************************************************************/

namespace Gica\Cqrs\Testing;


use Gica\Cqrs\Command;
use Gica\Cqrs\Command\CommandApplier;
use Gica\Cqrs\Command\CommandSubscriber;
use Gica\Cqrs\Event;
use Gica\Cqrs\Event\EventDispatcher;
use Gica\Cqrs\Event\EventDispatcher\EventDispatcherBySubscriber;
use Gica\Cqrs\Event\EventsApplier\EventsApplierOnAggregate;
use Gica\Cqrs\Event\EventSubscriber\ManualEventSubscriber;
use Gica\Cqrs\Event\EventWithMetaData;
use Gica\Cqrs\Event\MetaData;
use Gica\Cqrs\Testing\Exceptions\TooManyEventsFired;
use Gica\Cqrs\Testing\Exceptions\ExpectedEventNotYielded;
use Gica\Cqrs\Testing\Exceptions\NoExceptionThrown;
use Gica\Cqrs\Testing\Exceptions\WrongEventClassYielded;
use Gica\Cqrs\Testing\Exceptions\WrongExceptionClassThrown;
use Gica\Cqrs\Testing\Exceptions\WrongExceptionMessageWasThrown;
use Gica\Types\Guid;

class BddAggregateTestHelper
{
    private $aggregateId;

    /** @var EventDispatcher */
    private $eventDispatcher;

    private $priorEvents = [];

    private $command;
    private $aggregate;

    /** @var EventsApplierOnAggregate */
    private $eventsApplierOnAggregate;

    /** @var CommandApplier */
    private $commandApplier;
    /**
     * @var CommandSubscriber
     */
    private $commandSubscriber;

    public function __construct(
        CommandSubscriber $commandSubscriber
    )
    {
        $this->commandSubscriber = $commandSubscriber;
        $this->eventDispatcher = new EventDispatcherBySubscriber(new ManualEventSubscriber());
        $this->eventsApplierOnAggregate = new EventsApplierOnAggregate();
        $this->commandApplier = new CommandApplier();

        $this->priorEvents = [];
        $this->command = null;
    }

    public function getCommandSubscriber(): CommandSubscriber
    {
        return $this->commandSubscriber;
    }

    public function onAggregate($aggregate)
    {
        $this->aggregate = $aggregate;
        $this->aggregateId = 123;
    }

    public function given(...$priorEvents)
    {
        $this->priorEvents = $this->decorateEventsWithMetadata($priorEvents);
    }

    /**
     * @param Event[] $priorEvents
     * @return EventWithMetaData[]
     */
    private function decorateEventsWithMetadata(array $priorEvents)
    {
        return array_map(function (Event $event) {
            return $this->decorateEventWithMetaData($event);
        }, $priorEvents);
    }

    public function when($command)
    {
        $this->command = $command;
    }

    public function then(...$expectedEvents)
    {
        $this->eventsApplierOnAggregate->applyEventsOnAggregate($this->aggregate, $this->priorEvents);

        $newEvents = $this->executeCommand($this->command);

        $this->assertTheseEvents($expectedEvents, $newEvents);
    }

    public function executeCommand(Command $command)
    {
        $handler = $this->getCommandSubscriber()->getHandlerForCommand($command);

        $newEventsGenerator = $this->commandApplier->applyCommand($this->aggregate, $command, $handler->getMethodName());

        /** @var EventWithMetaData[] $eventsWithMetaData */
        $eventsWithMetaData = [];

        $newEvents = [];

        foreach ($newEventsGenerator as $event) {
            $eventWithMetaData = $this->decorateEventWithMetaData($event);

            $this->eventsApplierOnAggregate->applyEventsOnAggregate($this->aggregate, [$eventWithMetaData]);

            $eventsWithMetaData[] = $eventWithMetaData;
            $newEvents[] = $event;
        }

        foreach ($eventsWithMetaData as $eventWithMetaData) {
            $this->eventDispatcher->dispatchEvent($eventWithMetaData);
        }

        return $newEvents;
    }

    private function decorateEventWithMetaData(Event $event): EventWithMetaData
    {
        return new EventWithMetaData($event, $this->factoryMetaData());
    }

    public function thenShouldFailWith($expectedExceptionClass, $expectedExceptionMessage = null)
    {
        try {
            $handler = $this->getCommandSubscriber()->getHandlerForCommand($this->command);

            $this->eventsApplierOnAggregate->applyEventsOnAggregate($this->aggregate, $this->priorEvents);

            iterator_to_array(
                $this->commandApplier->applyCommand(
                    $this->aggregate, $this->command, $handler->getMethodName()));

            throw new NoExceptionThrown(
                sprintf("Exception '%s' was is expected, but none was thrown", $expectedExceptionClass));

        } catch (\Throwable $thrownException) {

            if ($thrownException instanceof NoExceptionThrown) {
                throw $thrownException;//rethrown
            }

            if (!$this->isClassOrSubClass($expectedExceptionClass, $thrownException)) {
                throw new WrongExceptionClassThrown(
                    sprintf(
                        "Exception '%s' was expected, but '%s(%s)' was thrown",
                        $expectedExceptionClass,
                        get_class($thrownException),
                        $thrownException->getMessage()));
            }

            if ($expectedExceptionMessage && $thrownException->getMessage() != $expectedExceptionMessage) {
                throw new WrongExceptionMessageWasThrown(
                    sprintf(
                        "Exception with message '%s' was expected, but '%s' was thrown",
                        $expectedExceptionMessage,
                        $thrownException->getMessage()));
            }

        }
    }

    public function assertTheseEvents(array $expectedEvents, array $actualEvents)
    {
        $expectedEvents = array_values($expectedEvents);
        $actualEvents = array_values($actualEvents);

        $this->checkForToFewEvents($expectedEvents, $actualEvents);
        $this->checkForToManyEvents(count($actualEvents) - count($expectedEvents));
    }

    private function checkForToFewEvents(array $expectedEvents, array $actualEvents)
    {
        foreach ($expectedEvents as $k => $expectedEvent) {
            if (!isset($actualEvents[$k])) {
                throw new ExpectedEventNotYielded(
                    "Expected event no. $k not fired (should have class: " . get_class($expectedEvent) . ")");
            }

            $actualEvent = $actualEvents[$k];

            if ($this->hashEvent($expectedEvent) != $this->hashEvent($actualEvent)) {
                throw new WrongEventClassYielded(
                    "Wrong event no. {$k} of class " . get_class($expectedEvent) . " emitted");
            }
        }
    }

    private function checkForToManyEvents(int $additionalCount)
    {
        if ($additionalCount > 0) {
            throw new TooManyEventsFired(
                sprintf("Additional %d events fired", $additionalCount));
        }
    }

    public function hashEvent($event)
    {
        return array_merge(['___class' => get_class($event)], (array)($event));
    }

    private function factoryMetaData(): MetaData
    {
        return new MetaData(
            $this->aggregateId, get_class($this->aggregate), new \DateTimeImmutable(), new Guid()
        );
    }

    private function isClassOrSubClass(string $parentClass, $childClass): bool
    {
        return get_class($childClass) == $parentClass || is_subclass_of($childClass, $parentClass);
    }
}