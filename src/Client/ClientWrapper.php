<?php

declare(strict_types=1);

namespace Okvpn\Bundle\DatadogBundle\Client;

use Okvpn\Bundle\DatadogBundle\Stream\UdpStreamWriter;
use Graze\DogStatsD\Client;
use Psr\Log\LoggerInterface;

class ClientWrapper extends Client
{
    protected const MAX_REPEAT_COUNT = 1;

    /** @var array<string, mixed> */
    protected array $options = [];

    protected int $repeatCount = 0;

    public function __construct(protected ?LoggerInterface $logger = null, ?string $instanceId = null)
    {
        parent::__construct($instanceId);
    }

    /**
     * {@inheritdoc}
     */
    public function configure(array $options = []): ClientWrapper|Client
    {
        $this->options = $options;
        return parent::configure($options);
    }

    /**
     * {@inheritdoc}
     */
    public function event($title, $text, array $metadata = [], array $tags = []): ClientWrapper|bool|Client|static
    {
        if (!$this->dataDog) {
            return $this;
        }

        $prefix = $this->namespace ? $this->namespace . '.' : '';
        $title = $prefix . $title;

        $text = str_replace(["\r", "\n"], ['', "\\n"], $text);
        // Todo bugfix
        $metric = sprintf('_e{%d,%d}', strlen($title), strlen($text));
        $value = sprintf('%s|%s', $title, $text);

        foreach ($metadata as $key => $data) {
            if (isset($this->eventMetaData[$key])) {
                $value .= sprintf('|%s:%s', $this->eventMetaData[$key], $data);
            }
        }

        $value .= $this->formatTags(array_merge($this->tags, $tags));

        return $this->sendMessages([
            sprintf('%s:%s', $metric, $value),
        ]);
    }

    public function getOption(string $option, mixed $default = null): mixed
    {
        return $this->options[$option] ?? $default;
    }

    /**
     * Array of option
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * {@inheritdoc}
     */
    protected function sendMessages(array $messages): ClientWrapper|bool|Client|static
    {
        if ($this->repeatCount >= self::MAX_REPEAT_COUNT) {
            return false;
        }

        try {
            if (is_null($this->stream)) {
                $this->stream = new UdpStreamWriter(
                    $this->instanceId,
                    $this->host,
                    $this->port,
                    $this->onError,
                    $this->timeout
                );
            }
            $this->message = implode("\n", $messages);
            $this->written = $this->stream->write($this->message);

            return $this;
        } catch (\Throwable $exception) {
            $this->repeatCount++;
            $this->logger?->error($exception->getMessage(), ['message' => $messages]);
            return false;
        }
    }

    /**
     * @param string[] $tags A list of tags to apply to each message
     */
    private function formatTags(array $tags = []): string
    {
        if (!$this->dataDog || count($tags) === 0) {
            return '';
        }

        $result = [];
        foreach ($tags as $key => $value) {
            if (is_numeric($key)) {
                $result[] = $value;
            } else {
                $result[] = sprintf('%s:%s', $key, $value);
            }
        }

        return sprintf('|#%s', implode(',', $result));
    }
}
