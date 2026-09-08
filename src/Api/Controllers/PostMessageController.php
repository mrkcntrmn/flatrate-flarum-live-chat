<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Api\Controllers;

use FlatRate\LiveChat\Api\Serializers\MessageSerializer;
use FlatRate\LiveChat\Commands\PostMessage;
use Flarum\Api\Controller\AbstractShowController;
use Illuminate\Contracts\Bus\Dispatcher;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;
use Illuminate\Support\Arr;

class PostMessageController extends AbstractShowController
{
    public $serializer = MessageSerializer::class;

    protected $bus;

    public $include = ['user', 'deleted_by', 'chat'];

    public function __construct(Dispatcher $bus)
    {
        $this->bus = $bus;
    }

    protected function data(ServerRequestInterface $request, Document $document)
    {
        $actor = $request->getAttribute('actor');
        $data = Arr::get($request->getParsedBody(), 'data', []);

        // CHAT_IP_PERSISTENCE=false — do not forward REMOTE_ADDR into message build.
        return $this->bus->dispatch(
            new PostMessage($actor, $data, null)
        );
    }
}
