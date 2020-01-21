<?php

namespace App\Services;

use App\Commands\NewOpportunityCommand;
use App\Commands\OptionsCommand;
use App\Console\Commands\BotPopulateChannel;
use App\Contracts\Repositories\OpportunityRepository;
use App\Exceptions\TelegramOpportunityException;
use App\Helpers\BotHelper;
use App\Helpers\ExtractorHelper;
use App\Helpers\SanitizerHelper;
use App\Models\Opportunity;
use Exception;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Prettus\Repository\Contracts\RepositoryInterface;
use Telegram\Bot\Api;
use Telegram\Bot\BotsManager;
use Telegram\Bot\Exceptions\TelegramResponseException;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Objects\CallbackQuery;
use Telegram\Bot\Objects\Document;
use Telegram\Bot\Objects\Message;
use Telegram\Bot\Objects\PhotoSize;
use Telegram\Bot\Objects\Update;

/**
 * Class CommandsHandler
 */
class CommandsHandler
{

    /** @var string */
    private $botName;

    /** @var Api */
    private $telegram;

    /** @var Update */
    private $update;
    /**
     * @var OpportunityRepository
     */
    private $repository;

    /**
     * CommandsHandler constructor.
     * @param BotsManager $botsManager
     * @param string $botName
     * @param OpportunityRepository|RepositoryInterface $repository
     */
    public function __construct(
        BotsManager $botsManager,
        string $botName,
        OpportunityRepository $repository
    ) {
        $this->botName = $botName;
        $this->telegram = $botsManager->bot($botName);
        $this->repository = $repository;
    }

    /**
     * Process the update coming from bot interface
     *
     * @param Update $update
     * @return mixed
     * @throws TelegramSDKException
     */
    public function processUpdate(Update $update): void
    {
        $this->update = $update;
        try {
            /** @var Message $message */
            $message = $update->getMessage();
            /** @var CallbackQuery $callbackQuery */
            $callbackQuery = $update->get('callback_query');

            if (strpos($message->text, '/') === 0) {
                $command = explode(' ', $message->text);
                $this->processCommand($command[0]);
            }
            if (filled($callbackQuery)) {
                $this->processCallbackQuery($callbackQuery);
            } elseif (filled($message) && strpos($message->text, '/') !== 0) {
                $this->processMessage($message);
            }
        } catch (TelegramOpportunityException $exception) {
            $this->sendMessage($exception->getMessage());
        } catch (Exception $exception) {
            throw $exception;
        }
    }

    /**
     * Process the Callback query coming from bot interface
     *
     * @param CallbackQuery $callbackQuery
     * @throws TelegramSDKException
     */
    private function processCallbackQuery(CallbackQuery $callbackQuery): void
    {
        $data = $callbackQuery->get('data');
        $data = explode(' ', $data);
        $opportunity = null;
        if (is_numeric($data[1])) {
            $opportunity = $this->repository->find($data[1]);
        }
        switch ($data[0]) {
            case Opportunity::CALLBACK_APPROVE:
                if ($opportunity) {
                    $opportunity->status = Opportunity::STATUS_ACTIVE;
                    $opportunity->save();
                    Artisan::call(
                        'bot:populate:channel',
                        [
                            'type' => BotPopulateChannel::TYPE_SEND,
                            'opportunity' => $opportunity->id
                        ]
                    );
                    $this->sendMessage(Artisan::output());
                }
                break;
            case Opportunity::CALLBACK_REMOVE:
                if ($opportunity) {
                    $opportunity->delete();
                }
                break;
            case OptionsCommand::OPTIONS_COMMAND:
                if (in_array($data[1], [BotPopulateChannel::TYPE_PROCESS, BotPopulateChannel::TYPE_NOTIFY], true)) {
                    Artisan::call('bot:populate:channel', ['type' => $data[1]]);
                    $this->sendMessage(Artisan::output());
                }
                break;
            default:
                Log::info('SWITCH_DEFAULT', $data);
                $this->processCommand($data[0]);
                break;
        }
        try {
            $this->telegram->deleteMessage([
                'chat_id' => Config::get('telegram.admin'),
                'message_id' => $callbackQuery->message->messageId
            ]);
        } catch (TelegramResponseException $exception) {
            Log::info('COMMAND', $data);
            Log::info('DELETE_MESSAGE', [$exception]);
            Log::info('CALLBACK_MESSAGE', [$callbackQuery->message]);
            /**
             * A message can only be deleted if it was sent less than 48 hours ago.
             * Bots can delete outgoing messages in groups and supergroups.
             * Bots granted can_post_messages permissions can delete outgoing messages in channels.
             * If the bot is an administrator of a group, it can delete any message there.
             * If the bot has can_delete_messages permission in a supergroup or a channel, it can delete any message there. Returns True on success.
             */
            if ($exception->getCode() != 400) {
                throw $exception;
            }
        }
    }

    /**
     * Process the messages coming from bot interface
     *
     * @param Message $message
     * @throws Exception|TelegramOpportunityException
     */
    private function processMessage(Message $message): void
    {
        /** @var Message $reply */
        $reply = $message->getReplyToMessage();
        /** @var PhotoSize $photos */
        /** @var PhotoSize $photo */
        $photos = $message->photo;
        /** @var Document $document */
        $document = $message->document;
        /** @var string $caption */
        $caption = $message->caption;

        Log::info('MESSAGE_TEXT', [$message->text,]);
        Log::info('REPLY', [$reply]);
        Log::info('PHOTOS', [$photos]);
        Log::info('DOCUMENT', [$document]);
        Log::info('CAPTION', [$caption]);

        if (
            (
                (filled($reply) && $reply->from->isBot && $reply->text === NewOpportunityCommand::TEXT) ||
                (!$message->from->isBot && $message->chat->type === BotHelper::TG_CHAT_TYPE_PRIVATE)
            ) &&
            !in_array($message->text, $this->telegram->getCommands(), true)
        ) {
            if (blank($message->text) && blank($caption)) {
                throw new TelegramOpportunityException(
                    'Envie um texto da vaga, ou o nome da vaga na legenda da imagem/documento.'
                );
            }

            $text = $message->text ?? $caption;

            $urls = ExtractorHelper::extractUrls($text);
            $emails = ExtractorHelper::extractEmail($text);

            if (blank($urls) && blank($emails)) {
                throw new TelegramOpportunityException(
                    'Envie o texto da vaga, contendo uma URL ou E-mail para se candidatar.'
                );
            }

            $files = [];
            if (filled($photos)) {
                foreach ($photos as $photo) {
                    $files[] = $photo;
                }
            }
            if (filled($document)) {
                $files[] = $document->first();
            }

            $title = str_replace("\n", ' ', $text);

            $opportunity = $this->repository->make([
                Opportunity::TITLE => Str::limit($title, 50),
                Opportunity::DESCRIPTION => SanitizerHelper::sanitizeBody($text),
                Opportunity::FILES => $files,
                Opportunity::URL => implode(', ', $urls),
                Opportunity::ORIGIN => implode('|', [$this->botName, $message->from->username ?:
                    $message->from->firstName . ' ' . $message->from->lastName]),
                Opportunity::LOCATION => implode(' / ', ExtractorHelper::extractLocation($text)),
                Opportunity::TAGS => ExtractorHelper::extractTags($text),
                Opportunity::EMAILS => SanitizerHelper::replaceMarkdown(implode(', ', $emails)),
                Opportunity::POSITION => null,
                Opportunity::SALARY => null,
                Opportunity::COMPANY => null,
            ]);

            $opportunity->status = Opportunity::STATUS_INACTIVE;
            $opportunity->telegram_user_id = $message->from->id;

            $opportunity->save();

            $this->sendOpportunityToApproval($opportunity);
        }

        $newMembers = $message->newChatMembers;
        if (filled($newMembers)) {
            Log::info('NEW_MEMBER', [$newMembers]);
        }

        // TODO: Think more about this
        /*if (filled($newMembers)) {
            foreach ($newMembers as $newMember) {
                $name = $newMember->getFirstName();
                return $this->telegram->getCommandBus()->execute('start', $this->update, $name);
            }
        }*/
    }

    /**
     * Send opportunity to approval
     *
     * @param Opportunity $opportunity
     */
    private function sendOpportunityToApproval(Opportunity $opportunity): void
    {
        Artisan::call(
            'bot:populate:channel',
            [
                'type' => BotPopulateChannel::TYPE_APPROVAL,
                'opportunity' => $opportunity->id,
            ]
        );
    }

    /**
     * Process the command
     *
     * @param string $command
     */
    private function processCommand(string $command): void
    {
        $command = str_replace('/', '', $command);

        $commands = $this->telegram->getCommands();

        if (array_key_exists($command, $commands)) {
            $this->telegram->processCommand($this->update);
        }
    }

    /**
     * Process the command
     *
     * @param string $command
     */
    private function triggerCommand(string $command): void
    {
        $command = str_replace('/', '', $command);

        $commands = $this->telegram->getCommands();

        if (array_key_exists($command, $commands)) {
            $this->telegram->triggerCommand($command, $this->update);
        }
    }

    /**
     * @param $message
     * @throws TelegramSDKException
     */
    private function sendMessage($message)
    {
        if (filled($message)) {
            $this->telegram->sendMessage([
                'chat_id' => $this->update->getChat()->id,
                'reply_to_message_id' => $this->update->getMessage()->messageId,
                'text' => $message
            ]);
        }
    }
}
