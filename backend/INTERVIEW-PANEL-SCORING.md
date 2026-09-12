# Interview panel scoring

## Enable the feature

From the `backend` directory, run:

```powershell
php artisan migrate
```

Migration `2026_09_10_000016_create_structured_interview_panels.php` creates the panel assignment, scorecard, individual evaluation, and criterion score tables.

## Workflow

1. A System Administrator assigns the **Interview Panel Member** role to the required employees in **User management**.
2. HR opens **Interviews**, selects one or more shortlisted candidates, selects the panel members, and defines the weighted criteria. Criterion weights must total 100%.
3. Every assigned panel member signs in and submits their own scorecard. Their evaluation is locked after submission.
4. The combined score is the average of all submitted members' weighted scores. An interview remains **In Progress** until every assigned member submits.
5. HR, the HOD responsible for the vacancy's department, and the Managing Director can open **Interviews** to see:
   - each panel member's weighted score;
   - the member's criterion-by-criterion marks, recommendation, and comments;
   - submission progress and the combined score.
6. HR can reopen a particular member's evaluation if a correction is required. The interview returns to **In Progress** until the corrected score is submitted.
7. The combined score is used automatically for candidate ranking and final-selection review.

## Example scorecard

| Criterion | Weight |
| --- | ---: |
| Technical knowledge | 40% |
| Relevant experience | 25% |
| Communication | 20% |
| Leadership | 15% |

If Member A's weighted score is 82 and Member B's weighted score is 88, the combined score shown to HR, the relevant HOD, and the Managing Director is 85/100.
