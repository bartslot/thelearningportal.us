<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The catalog of lesson-module types (Epic K). A module's `type` resolves through
 * App\Lessons\Modules\ModuleRegistry to a class implementing the LessonModuleType contract.
 *
 * Build order lives in .claude/epic-k-lesson-composer-plan.md §5 — not every case has an
 * implementation yet; rows can exist as data before their renderer ships.
 */
enum ModuleType: string
{
    // MVP set (K-1, K-4, K-6)
    case TitleBlock = 'title_block';
    case Intro = 'intro';
    case PriorKnowledge = 'prior_knowledge';      // "What do you already know?" (K-4)
    case StoryBlock = 'story_block';
    case QuizMcq = 'quiz_mcq';
    case Reflection = 'reflection';
    case Conclusion = 'conclusion';

    // Richer set (later phases)
    case TimelineMap = 'timeline_map';         // Epic J embed
    case QuizImage = 'quiz_image';
    case QuizMap = 'quiz_map';
    case HotspotImage = 'hotspot_image';
    case StrategyGame = 'strategy_game';        // references a StrategyGame via content_ref
    case MapChallenge = 'map_challenge';
    case ThreeDModel = 'three_d_model';        // Sketchfab embed (K-5)
    case ImageAnalysis = 'image_analysis';       // Epic G5
    case TeacherInstruction = 'teacher_instruction';
    case StudentTask = 'student_task';

    /** Teacher-facing label (localizable). */
    public function label(): string
    {
        return match ($this) {
            self::TitleBlock => __('Title'),
            self::Intro => __('Intro'),
            self::PriorKnowledge => __('What do you already know?'),
            self::StoryBlock => __('Story'),
            self::QuizMcq => __('Multiple-choice quiz'),
            self::Reflection => __('Reflection'),
            self::Conclusion => __('Conclusion'),
            self::TimelineMap => __('Timeline + map'),
            self::QuizImage => __('Image quiz'),
            self::QuizMap => __('Map quiz'),
            self::HotspotImage => __('Hotspot image'),
            self::StrategyGame => __('Strategy game'),
            self::MapChallenge => __('Map challenge'),
            self::ThreeDModel => __('3D model'),
            self::ImageAnalysis => __('Image / document analysis'),
            self::TeacherInstruction => __('Teacher instruction'),
            self::StudentTask => __('Student task'),
        };
    }
}
