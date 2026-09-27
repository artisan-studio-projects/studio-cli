<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Concerns;

use ArtisanStudio\StudioCli\ActivityLog;
use ArtisanStudio\StudioCli\Errand;
use ArtisanStudio\StudioCli\Studio;
use Illuminate\Container\Container;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

trait AnswersArtisans
{
    private const int ASK_THE_STUDIO_EVERY = 5;

    /**
     * @var array{id: int, name: string, arguments: array<string, mixed>, workflow: string}|null
     */
    private ?array $artisanQuestion = null;

    private ?Process $artisanAnswer = null;

    private bool $artisanAnswerTookTooLong = false;

    private int $artisansAskedAt = 0;

    protected function answerArtisans(): void
    {
        match (true) {
            $this->artisanAnswer === null && time() - $this->artisansAskedAt >= self::ASK_THE_STUDIO_EVERY => $this->takeTheNextQuestion(),
            $this->artisanAnswer !== null && ! $this->stillAnswering() => $this->sendTheAnswer(),
            default => null,
        };
    }

    protected function stopAnswering(): void
    {
        if ($this->artisanAnswer === null || $this->artisanQuestion === null) {
            return;
        }

        $this->artisanAnswer->stop(0);
        $this->answer($this->artisanQuestion, [
            ...$this->errands()->answerFrom($this->artisanAnswer),
            'exit_code' => 1,
            'error' => 'The studio was closed on this machine before it finished.',
        ]);
        $this->artisanAnswer = null;
        $this->artisanQuestion = null;
    }

    private function takeTheNextQuestion(): void
    {
        $this->artisansAskedAt = time();
        $question = $this->nextQuestion();

        if ($question === null) {
            return;
        }

        $name = (string) $question['name'];
        $this->note($question, 'Answering', 'cyan');
        $process = $this->errands()->start($name, (array) $question['arguments']);

        if ($process === null) {
            $this->answer($question, $this->errands()->unknown($name));

            return;
        }

        $this->artisanQuestion = $question;
        $this->artisanAnswer = $process;
        $this->artisanAnswerTookTooLong = false;
    }

    /**
     * @return array{id: int, name: string, arguments: array<string, mixed>, workflow: string}|null
     */
    private function nextQuestion(): ?array
    {
        $studio = Container::getInstance()->make(Studio::class);

        try {
            return $studio->isLinked() ? $studio->nextCommand() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function stillAnswering(): bool
    {
        try {
            $this->artisanAnswer?->checkTimeout();
        } catch (ProcessTimedOutException) {
            $this->artisanAnswerTookTooLong = true;

            return false;
        }

        return (bool) $this->artisanAnswer?->isRunning();
    }

    private function sendTheAnswer(): void
    {
        if ($this->artisanAnswer !== null && $this->artisanQuestion !== null) {
            $this->answer($this->artisanQuestion, $this->artisanAnswerTookTooLong
                ? $this->errands()->tookTooLong($this->artisanAnswer)
                : $this->errands()->answerFrom($this->artisanAnswer));
        }

        $this->artisanAnswer = null;
        $this->artisanQuestion = null;
        $this->artisansAskedAt = 0;
    }

    /**
     * @param  array{id: int, name: string, arguments: array<string, mixed>, workflow: string}  $question
     * @param  array{output: string, exit_code: int, error: ?string}  $answer
     */
    private function answer(array $question, array $answer): void
    {
        try {
            Container::getInstance()->make(Studio::class)->answerCommand((int) $question['id'], $answer);
        } catch (Throwable) {
            $answer = [...$answer, 'error' => 'Could not reach the studio with the answer.'];
        }

        $this->note($question, $answer['exit_code'] === 0 && $answer['error'] === null ? 'Answered' : 'Answered with a problem', $answer['exit_code'] === 0 && $answer['error'] === null ? 'green' : 'amber');
    }

    /**
     * @param  array{id: int, name: string, arguments: array<string, mixed>, workflow: string}  $question
     */
    private function note(array $question, string $label, string $colour): void
    {
        Container::getInstance()->make(ActivityLog::class)->add(
            ['agent' => 'your app', 'label' => $label, 'detail' => $this->errands()->describe((string) $question['name']), 'colour' => $colour, 'kind' => 'question', 'workflow' => (string) $question['workflow']],
            'question-'.$question['id'],
        );
    }

    private function errands(): Errand
    {
        return Container::getInstance()->make(Errand::class);
    }
}
