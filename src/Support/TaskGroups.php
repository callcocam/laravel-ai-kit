<?php

namespace Callcocam\AiKit\Support;

/**
 * Os grupos de tarefas com cota própria (config ai-kit.groups). A tarefa fora de
 * qualquer grupo, ou nenhuma tarefa, é do grupo `default`.
 */
final class TaskGroups
{
    public const DEFAULT = 'default';

    public static function of(?string $task): string
    {
        foreach (self::all() as $group => $tasks) {
            if ($task !== null && in_array($task, $tasks, true)) {
                return $group;
            }
        }

        return self::DEFAULT;
    }

    /**
     * @return array<string, list<string>>
     */
    public static function all(): array
    {
        /** @var array<string, list<string>> */
        return (array) config('ai-kit.groups', []);
    }

    /**
     * As tarefas de todos os grupos nomeados (o `default` é "nenhuma destas").
     *
     * @return list<string>
     */
    public static function grouped(): array
    {
        return array_merge(...array_values(self::all()));
    }
}
