<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Content\Flow\Dispatching;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\AbstractRuleLoader;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Flow\Dispatching\Action\AddCustomerTagAction;
use Shopware\Core\Content\Flow\Dispatching\Action\AddOrderTagAction;
use Shopware\Core\Content\Flow\Dispatching\Action\StopFlowAction;
use Shopware\Core\Content\Flow\Dispatching\FlowExecutor;
use Shopware\Core\Content\Flow\Dispatching\FlowState;
use Shopware\Core\Content\Flow\Dispatching\StorableFlow;
use Shopware\Core\Content\Flow\Dispatching\Struct\ActionSequence;
use Shopware\Core\Content\Flow\Dispatching\Struct\Flow;
use Shopware\Core\Content\Flow\Dispatching\Struct\IfSequence;
use Shopware\Core\Content\Flow\Dispatching\Struct\Sequence;
use Shopware\Core\Content\Flow\Exception\ExecuteSequenceException;
use Shopware\Core\Content\Flow\Rule\FlowRuleScope;
use Shopware\Core\Content\Flow\Rule\FlowRuleScopeBuilder;
use Shopware\Core\Content\Flow\Rule\OrderTagRule;
use Shopware\Core\Content\Rule\RuleCollection;
use Shopware\Core\Content\Rule\RuleEntity;
use Shopware\Core\Framework\App\Event\AppFlowActionEvent;
use Shopware\Core\Framework\App\Flow\Action\AppFlowActionProvider;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\RuleAreas;
use Shopware\Core\Framework\Event\OrderAware;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\Framework\Test\TestDataCollection;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\Tag\TagCollection;
use Shopware\Core\System\Tag\TagEntity;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @package business-ops
 *
 * @internal
 *
 * @covers \Shopware\Core\Content\Flow\Dispatching\FlowExecutor
 */
class FlowExecutorTest extends TestCase
{
    private const ACTION_ADD_ORDER_TAG = 'action.add.order.tag';
    private const ACTION_ADD_CUSTOMER_TAG = 'action.add.customer.tag';
    private const ACTION_STOP_FLOW = 'action.stop.flow';

    /**
     * @dataProvider actionsProvider
     *
     * @param array<int, mixed> $actionSequencesExecuted
     * @param array<int, mixed> $actionSequencesTrueCase
     * @param array<int, mixed> $actionSequencesFalseCase
     *
     * @throws ExecuteSequenceException
     */
    public function testExecute(array $actionSequencesExecuted, array $actionSequencesTrueCase, array $actionSequencesFalseCase, ?string $appAction = null): void
    {
        $ids = new TestDataCollection();
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $appFlowActionProvider = $this->createMock(AppFlowActionProvider::class);
        $ruleLoader = $this->createMock(AbstractRuleLoader::class);
        $scopeBuilder = $this->createMock(FlowRuleScopeBuilder::class);

        $addOrderTagAction = $this->createMock(AddOrderTagAction::class);
        $addCustomerTagAction = $this->createMock(AddCustomerTagAction::class);
        $stopFlowAction = $this->createMock(StopFlowAction::class);
        $actions = [
            self::ACTION_ADD_ORDER_TAG => $addOrderTagAction,
            self::ACTION_ADD_CUSTOMER_TAG => $addCustomerTagAction,
            self::ACTION_STOP_FLOW => $stopFlowAction,
        ];

        $actionSequences = [];
        if (!empty($actionSequencesExecuted)) {
            foreach ($actionSequencesExecuted as $actionSequenceExecuted) {
                $actionSequence = new ActionSequence();
                $actionSequence->sequenceId = $ids->get($actionSequenceExecuted);
                $actionSequence->action = $actionSequenceExecuted;

                $actionSequences[] = $actionSequence;
            }
        }

        $context = Context::createDefaultContext();
        if (!empty($actionSequencesTrueCase)) {
            $condition = new IfSequence();
            $condition->sequenceId = $ids->get('true_case');
            $condition->ruleId = $ids->get('ruleId');

            $context = Context::createDefaultContext();
            $context->setRuleIds([$ids->get('ruleId')]);

            foreach ($actionSequencesTrueCase as $actionSequenceTrueCase) {
                $actionSequence = new ActionSequence();
                $actionSequence->sequenceId = $ids->get($actionSequenceTrueCase);
                $actionSequence->action = $actionSequenceTrueCase;

                $condition->trueCase = $actionSequence;
            }

            $actionSequences[] = $condition;
        }

        if (!empty($actionSequencesFalseCase)) {
            $condition = new IfSequence();
            $condition->sequenceId = $ids->get('false_case');
            $condition->ruleId = $ids->get('ruleId');

            $context = Context::createDefaultContext();

            foreach ($actionSequencesFalseCase as $actionSequenceFalseCase) {
                $actionSequence = new ActionSequence();
                $actionSequence->sequenceId = $ids->get($actionSequenceFalseCase);
                $actionSequence->action = $actionSequenceFalseCase;

                $condition->falseCase = $actionSequence;
            }

            $actionSequences[] = $condition;
        }

        if ($appAction) {
            $appActionSequence = new ActionSequence();
            $appActionSequence->appFlowActionId = $ids->get('AppActionId');
            $appActionSequence->sequenceId = $ids->get('AppActionSequenceId');
            $appActionSequence->action = 'app.action';
            $appFlowActionProvider->expects(static::once())->method('getWebhookPayloadAndHeaders')->willReturn([
                'headers' => [],
                'payload' => [],
            ]);
            $eventDispatcher->expects(static::once())->method('dispatch')->with(
                new AppFlowActionEvent('app.action', [], []),
                'app.action'
            );
            $actionSequences[] = $appActionSequence;
        }

        $flow = new Flow($ids->get('flowId'), $actionSequences);

        $storableFlow = new StorableFlow('', $context);

        if (\in_array(self::ACTION_ADD_ORDER_TAG, array_merge_recursive($actionSequencesExecuted, $actionSequencesTrueCase, $actionSequencesFalseCase), true)) {
            $addOrderTagAction->expects(static::once())->method('handleFlow')->with($storableFlow);
        } else {
            $addOrderTagAction->expects(static::never())->method('handleFlow');
        }

        if (\in_array(self::ACTION_ADD_CUSTOMER_TAG, array_merge_recursive($actionSequencesExecuted, $actionSequencesTrueCase, $actionSequencesFalseCase), true)) {
            $addCustomerTagAction->expects(static::once())->method('handleFlow')->with($storableFlow);
        } else {
            $addCustomerTagAction->expects(static::never())->method('handleFlow');
        }

        if (\in_array(self::ACTION_STOP_FLOW, array_merge_recursive($actionSequencesExecuted, $actionSequencesTrueCase, $actionSequencesFalseCase), true)) {
            $stopFlowAction->expects(static::once())->method('handleFlow')->with($storableFlow);
        } else {
            $stopFlowAction->expects(static::never())->method('handleFlow');
        }

        $flowExecutor = new FlowExecutor($eventDispatcher, $appFlowActionProvider, $ruleLoader, $scopeBuilder, $actions);
        $flowExecutor->execute($flow, $storableFlow);
    }

    public static function actionsProvider(): \Generator
    {
        yield 'Single action executed' => [
            [
                self::ACTION_ADD_ORDER_TAG,
            ],
            [],
            [],
        ];

        yield 'Multiple actions executed' => [
            [
                self::ACTION_ADD_ORDER_TAG,
                self::ACTION_ADD_CUSTOMER_TAG,
                self::ACTION_STOP_FLOW,
            ],
            [],
            [],
        ];

        yield 'Action executed with true case' => [
            [],
            [
                self::ACTION_ADD_ORDER_TAG,
            ],
            [],
        ];

        yield 'Action executed with false case' => [
            [],
            [],
            [
                self::ACTION_ADD_ORDER_TAG,
            ],
        ];

        yield 'Action executed from App' => [
            [],
            [],
            [],
            'app.action',
        ];
    }

    public function testExecuteIfWithRuleEvaluation(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $appFlowActionProvider = $this->createMock(AppFlowActionProvider::class);
        $ruleLoader = $this->createMock(AbstractRuleLoader::class);
        $scopeBuilder = $this->createMock(FlowRuleScopeBuilder::class);

        $trueCaseSequence = new Sequence();
        $trueCaseSequence->assign(['sequenceId' => 'foobar']);
        $ruleId = Uuid::randomHex();
        $ifSequence = new IfSequence();
        $ifSequence->assign(['ruleId' => $ruleId, 'trueCase' => $trueCaseSequence]);

        $order = new OrderEntity();
        $tagId = Uuid::randomHex();
        $tag = new TagEntity();
        $tag->setId($tagId);
        $order->setTags(new TagCollection([$tag]));

        $flow = new StorableFlow('bar', Context::createDefaultContext());
        $flow->setFlowState(new FlowState());
        $flow->setData(OrderAware::ORDER, $order);

        $scopeBuilder->method('build')->willReturn(
            new FlowRuleScope($order, new Cart('test'), $this->createMock(SalesChannelContext::class))
        );

        $rule = new OrderTagRule(Rule::OPERATOR_EQ, [$tagId]);
        $ruleEntity = new RuleEntity();
        $ruleEntity->setId($ruleId);
        $ruleEntity->setPayload($rule);
        $ruleEntity->setAreas([RuleAreas::FLOW_AREA]);
        $ruleLoader->method('load')->willReturn(new RuleCollection([$ruleEntity]));

        $flowExecutor = new FlowExecutor($eventDispatcher, $appFlowActionProvider, $ruleLoader, $scopeBuilder, []);
        $flowExecutor->executeIf($ifSequence, $flow);

        static::assertEquals($trueCaseSequence, $flow->getFlowState()->currentSequence);
    }
}
