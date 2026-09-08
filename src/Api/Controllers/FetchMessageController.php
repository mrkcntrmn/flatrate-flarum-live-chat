<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Api\Controllers;

use FlatRate\LiveChat\Api\Serializers\MessageSerializer;
use FlatRate\LiveChat\Commands\FetchMessage;
use Illuminate\Support\Arr;
use Flarum\Api\Controller\AbstractListController;
use Illuminate\Contracts\Bus\Dispatcher;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;

class FetchMessageController extends AbstractListController
{

    /**
     * The serializer instance for this request.
     *
     * @var MessageSerializer
     */
    public $serializer = MessageSerializer::class;

    /**
     * @var Dispatcher
     */
    protected $bus;

    /**
     * {@inheritdoc}
     */
    public $include = ['user', 'deleted_by', 'chat'];

    /**
     * @param Dispatcher $bus
     */
    public function __construct(Dispatcher $bus)
    {
        $this->bus = $bus;
    }

    /**
     * Get the data to be serialized and assigned to the response document.
     *
     * @param ServerRequestInterface $request
     * @param Document               $document
     * @return mixed
     */
    protected function data(ServerRequestInterface $request, Document $document)
    {
        $actor = $request->getAttribute('actor');
        $params = $request->getQueryParams();
        $chat_id = Arr::get($params, 'chat_id');
        if ($chat_id === null) {
            $chat_id = Arr::get($params, 'filter.chat_id');
        }
        if ($chat_id === null && isset($params['filter']) && is_array($params['filter'])) {
            $chat_id = $params['filter']['chat_id'] ?? null;
        }
        $query = Arr::get($params, 'query', 0);
        if ($chat_id === null || $chat_id === '') {
            throw new \InvalidArgumentException('chat_id is required');
        }

        return $this->bus->dispatch(
            new FetchMessage($query, $actor, (int) $chat_id)
        );
    }
}
