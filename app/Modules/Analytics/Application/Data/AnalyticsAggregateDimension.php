<?php

namespace App\Modules\Analytics\Application\Data;

enum AnalyticsAggregateDimension: string
{
    case ProblemTag = 'problem_tag';
    case FeedbackTheme = 'feedback_theme';
    case GoalTag = 'goal_tag';
    case Service = 'service';
    case Specialist = 'specialist';
    case AcquisitionSource = 'acquisition_source';
    case AcquisitionUnknown = 'acquisition_unknown';
    case PurchaseGroup = 'purchase_group';
    case BookingEvent = 'booking_event';
    case Segment = 'segment';
    case AgeRange = 'age_range';
    case AgeUnknown = 'age_unknown';
    case Sex = 'sex';
    case SexUnknown = 'sex_unknown';
    case Children = 'children';
    case ChildrenUnknown = 'children_unknown';
    case Country = 'country';
    case CountryUnknown = 'country_unknown';
}
