<?php

class Course
{
    // Paginated query that returns array of upcoming courses. Intentionally returns courses that are on todays date, even if time has passed.
    // This means users can still see courses that might be "in progress" on the day, rather than these being hidden in the past courses. 
    public static function getUpcomingCourses(int $userId, int $pageIndex, string $searchMinDate, string $searchMaxDate, string $searchTitle): array
    {
        global $connection;

        // Idea here is to return one more result than is dispayed.
        // Then, in the front end, if there are 13 results rather than 12, paginate controls know to allow navigation to next page.
        $limitEnd = (12 * $pageIndex) + 1;
        $limitStart = $limitEnd - 13;
        $upcomingQuery = null;

        $today = new DateTime();

        // Build where clause
        if (!empty($searchMinDate)) {
            $where = "WHERE DATE(t_courses.date) >= '$searchMinDate'";
        } else {
            $where = "WHERE DATE(t_courses.date) >= '{$today->format('Y-m-d')}'";
        }

        if (!empty($searchMaxDate)) {
            $where .= " AND DATE(t_courses.date) <= '$searchMaxDate'";
        }

        // Since title is not validated at _getUpcoming, should be prepared via prepared statement.
        if (!empty($searchTitle)) {
            $where .= " AND t_courses.title LIKE CONCAT('%', ?, '%')";
        }

        $upcomingQueryString = "SELECT t_courses.*, COUNT(t_enroll.iduser) AS 'enrolled', IFNULL(MAX(CASE WHEN t_enroll.iduser = ? then true end), false) as 'isUserEnrolled' FROM t_courses LEFT JOIN t_enroll ON t_enroll.idcourse = t_courses.id $where GROUP BY t_courses.id ORDER BY t_courses.date LIMIT ?, ?";

        // Prepare statement differently depending on if title is populated or not.
        if (!empty($searchTitle)) {
            $upcomingQuery = $connection->prepare($upcomingQueryString);
            $upcomingQuery->bind_param("isii", $userId, $searchTitle, $limitStart, $limitEnd);
        } else {
            $upcomingQuery = $connection->prepare($upcomingQueryString);
            $upcomingQuery->bind_param("iii", $userId, $limitStart, $limitEnd);
        }

        $upcomingQuery->execute();
        $queryResult = $upcomingQuery->get_result();
        $result = [];
        while ($row = $queryResult->fetch_assoc()) {
            array_push($result, $row);
        }
        return $result;
    }

    // Paginated query that returns past courses, intentionally doesn't consider a course as a past course until the day the course is on has fully passed.
    public static function getPastCourses(int $userId, int $pageIndex, string $searchMinDate, string $searchMaxDate, string $searchTitle): array
    {
        global $connection;

        // Idea here is to return one more result than is dispayed.
        // Then, in the front end, if there are 13 results rather than 12, paginate controls know to allow navigation to next page.
        $limitEnd = (12 * $pageIndex) + 1;
        $limitStart = $limitEnd - 13;
        $pastQuery = null;

        $today = new DateTime();

        // Build where clause
        if (!empty($searchMaxDate)) {
            $where = "WHERE DATE(t_courses.date) < '$searchMaxDate'";
        } else {
            $where = "WHERE DATE(t_courses.date) < '{$today->format('Y-m-d')}'";
        }

        if (!empty($searchMinDate)) {
            $where .= " AND DATE(t_courses.date) >= '$searchMinDate'";
        }

        // Since title is not validated at _getUpcoming, should be prepared via prepared statement.
        if (!empty($searchTitle)) {
            $where .= " AND t_courses.title LIKE CONCAT('%', ?, '%')";
        }

        $pastQueryString = "SELECT t_courses.*, COUNT(t_enroll.iduser) AS 'enrolled', IFNULL(MAX(CASE WHEN t_enroll.iduser = ? then true end), false) as 'isUserEnrolled' FROM t_courses LEFT JOIN t_enroll ON t_enroll.idcourse = t_courses.id $where GROUP BY t_courses.id ORDER BY t_courses.date DESC LIMIT ?, ?";

        // Prepare statement differently depending on if title is populated or not.
        if (!empty($searchTitle)) {
            $pastQuery = $connection->prepare($pastQueryString);
            $pastQuery->bind_param("isii", $userId, $searchTitle, $limitStart, $limitEnd);
        } else {
            $pastQuery = $connection->prepare($pastQueryString);
            $pastQuery->bind_param("iii", $userId, $limitStart, $limitEnd);
        }

        $pastQuery->execute();
        $queryResult = $pastQuery->get_result();
        $result = [];
        while ($row = $queryResult->fetch_assoc()) {
            array_push($result, $row);
        }
        return $result;
    }

    // Paginated query that returns an array of staff who are enrolled on a course.
    public static function getUsersEnrolledOnCourse(int $courseId, int $pageIndex): array
    {
        global $connection;

        // Get start/end for query limits. Return one more result than needed so front end can determine if next button should be active.
        $limitEnd = (5 * $pageIndex) + 1;
        $limitStart = $limitEnd - 6;

        $usersEnrolledQuery = $connection->prepare("SELECT t_users.id, t_users.firstName, t_users.lastName, t_users.email, t_users.jobTitle, t_enroll.dateCreated FROM t_enroll LEFT JOIN t_users ON t_enroll.iduser = t_users.id WHERE t_enroll.idcourse = ? ORDER BY dateCreated LIMIT $limitStart, $limitEnd");
        $usersEnrolledQuery->bind_param("i", $courseId);

        $success = $usersEnrolledQuery->execute();

        if (!$success) {
            throw new Exception("Fetching users enrolled on course failed.");
        }

        $queryResult = $usersEnrolledQuery->get_result();
        $result = [];
        while ($row = $queryResult->fetch_assoc()) {
            array_push($result, $row);
        }
        return $result;
    }

    // Admin based course deletion. Deletes course by course Id
    public static function deleteCourse(int $courseId)
    {
        global $connection;

        $delete = $connection->query("DELETE FROM t_courses WHERE id = $courseId");

        if (!$delete) {
            throw new Exception("Deleting course failed for an unknown reason.");
        }
    }

    // Admin based user unenrollment, takes course id and user id and removes matching record.
    public static function unenrolUser(int $courseId, int $userId)
    {
        global $connection;

        $unenrolUserQuery = $connection->prepare("DELETE FROM t_enroll WHERE iduser = ? AND idcourse = ?");
        $unenrolUserQuery->bind_param("ii", $userId, $courseId);
        $delete = $unenrolUserQuery->execute();

        if (!$delete) {
            throw new Exception("Deleting enrollment failed for an unknown reason.");
        }
    }

    // Edits course by course Id and by passing all values.
    public static function editCourse(int $courseId, string $title, string $date, float $duration, int $maxAttendees, string $description, string $link, string $location)
    {
        global $connection;

        // Ensure that title and description are not empty.
        if (empty($title) || empty($description)) {
            throw new Exception("Cannot create a course without required details (title, date, description). Please try again.");
        }

        // Ensure either a link or location is provided.
        if (empty($link) && empty($location)) {
            throw new Exception("A valid course must either have a link, location or both. Please try again.");
        }

        // Ensure date is provided and is a valid time.
        if (!empty($date)) {
            try {
                $testDateTime = new DateTime($date);
                $currentdateTime = new DateTime();
                $testDate = new DateTime($testDateTime->format("Y-m-d"));
                $currentDate = new DateTime($currentdateTime->format("Y-m-d"));
                $diff = $currentDate->diff($testDate)->format("%r%a");
                if ($diff < 0) {
                    throw new Exception();
                }
            } catch (Exception $ex) {
                throw new Exception("Provided course date is not valid. Please ensure date is today or later, and that it complies to the following formats: YYYY/MM/dd hh:mm or YYYY/MM/ddThh:mm."
                    . " For the best experience, we recommend using Edge or Chrome - which support date/time pickers for this input field.");
            }
        }
        else {
            throw new Exception("You must provide a date.");
        }

        // Validate that maxAttendees and duration are more than zero.
        if ($maxAttendees <= 0 || $duration <= 0.0) {
            throw new Exception("A course cannot have zero max attendees or a duration of zero.");
        }

        $editCourse = $connection->prepare("UPDATE t_courses SET title=?, date=?, duration=?, maxAttendees=?, description=?, link=?, location=? WHERE id = ?");
        $editCourse->bind_param("ssdisssi", $title, $date, $duration, $maxAttendees, $description, $link, $location, $courseId);
        $success = $editCourse->execute();

        if (!$success) {
            throw new Exception("Edit course operation failed");
        }
    }

    // Create a course.
    public static function createCourse(string $title, string $date, float $duration, int $maxAttendees, string $description, string $link, string $location): int
    {
        global $connection;

        // Ensure that title and description are not empty.
        if (empty($title) || empty($description)) {
            throw new Exception("Cannot create a course without required details (title, date, description). Please try again.");
        }

        // Ensure either a link or location is provided.
        if (empty($link) && empty($location)) {
            throw new Exception("A valid course must either have a link, location or both. Please try again.");
        }

        // Ensure date is provided and is a valid time.
        if (!empty($date)) {
            try {
                $testDateTime = new DateTime($date);
                $currentdateTime = new DateTime();
                $testDate = new DateTime($testDateTime->format("Y-m-d"));
                $currentDate = new DateTime($currentdateTime->format("Y-m-d"));
                $diff = $currentDate->diff($testDate)->format("%r%a");
                if ($diff < 0) {
                    throw new Exception();
                }
            } catch (Exception $ex) {
                throw new Exception("Provided course date is not valid. Please ensure date is today or later, and that it complies to the following formats: YYYY/MM/dd hh:mm or YYYY/MM/ddThh:mm."
                    . " For the best experience, we recommend using Edge or Chrome - which support date/time pickers for this input field.");
            }
        }
        else {
            throw new Exception("You must provide a date.");
        }

        // Validate that maxAttendees and duration are more than zero.
        if ($maxAttendees <= 0 || $duration <= 0.0) {
            throw new Exception("A course cannot have zero max attendees or a duration of zero.");
        }

        $createCourse = $connection->prepare("INSERT INTO t_courses (title, date, duration, maxAttendees, description, link, location) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $createCourse->bind_param("ssdisss", $title, $date, $duration, $maxAttendees, $description, $link, $location);
        $createCourse->execute();

        if (!empty($createCourse->error)) {
            throw new Exception("Create course operation failed");
        }

        return $createCourse->insert_id;
    }

    // Get a users upcoming enrolments, only works when user is logged into account.
    public static function getUpcomingEnrolments(int $pageIndex): array
    {
        global $connection;
        // Notice fetching of global account and use here, which is why user must be logged in!
        global $account;

        // Return one more result than necessary based on page index, so frontend can decide to enable/disable next button
        $limitEnd = (12 * $pageIndex) + 1;
        $limitStart = $limitEnd - 13;

        $upcomingEnrolmentsQuery = $connection->prepare("SELECT t_courses.*, (SELECT COUNT(iduser) FROM t_enroll WHERE idcourse = t_courses.id) AS 'enrolled' FROM t_courses LEFT JOIN t_enroll ON t_enroll.idcourse = t_courses.id WHERE DATE(t_courses.date) >= CURRENT_DATE AND t_enroll.iduser = {$account->getId()} GROUP BY t_courses.id ORDER BY t_courses.date LIMIT ?, ?");
        $upcomingEnrolmentsQuery->bind_param("ii", $limitStart, $limitEnd);

        $upcomingEnrolmentsQuery->execute();
        $queryResult = $upcomingEnrolmentsQuery->get_result();
        $result = [];
        while ($row = $queryResult->fetch_assoc()) {
            array_push($result, $row);
        }
        return $result;
    }

    // Get a users past enrolments, like upcoming enrolments, this requires a user to be logged in
    public static function getPastEnrolments($pageIndex): array
    {
        global $connection;
        global $account;

        $limitEnd = (12 * $pageIndex) + 1;
        $limitStart = $limitEnd - 13;

        $pastEnrolmentsQuery = $connection->prepare("SELECT t_courses.*, (SELECT COUNT(iduser) FROM t_enroll WHERE idcourse = t_courses.id) AS 'enrolled' FROM t_courses LEFT JOIN t_enroll ON t_enroll.idcourse = t_courses.id WHERE DATE(t_courses.date) < CURRENT_DATE AND t_enroll.iduser = {$account->getId()} GROUP BY t_courses.id ORDER BY t_courses.date LIMIT ?, ?");
        $pastEnrolmentsQuery->bind_param("ii", $limitStart, $limitEnd);

        $pastEnrolmentsQuery->execute();
        $queryResult = $pastEnrolmentsQuery->get_result();
        $result = [];
        while ($row = $queryResult->fetch_assoc()) {
            array_push($result, $row);
        }
        return $result;
    }
}
