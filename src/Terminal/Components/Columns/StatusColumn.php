<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Components\Columns;

class StatusColumn extends Column
{
    private const string BULLET = '●';

    /**
     * @var array<string, string>
     */
    private array $colours = [];

    /**
     * @param  array<string, string>  $colours
     */
    public function colours(array $colours): static
    {
        $this->colours = $colours;

        return $this->colour(fn (array $record): string => $this->colours[(string) ($record[$this->name] ?? '')] ?? 'cyan');
    }

    protected function text(array $record, mixed $state): string
    {
        return self::BULLET.' '.parent::text($record, $state);
    }
}
