This plugin provides a very light-weight database-backed queue service. There is one jobs table in the database, as defined in the install function of the plugin. Functions for adding jobs to the queue and deleting them are defined by the plugin for use anywhere in other plugins and themes.

Task descriptions can be strings or arrays (they will be serialized upon adding to the queue). The number of attempts to complete a task are recorded in the jobs table, along with the most recent status. No maximum on attempts is enforced by the service itself. This is left up to workers to decide if and when to declare an error status. Once a job is flaged as being in error status, it will not be available to be picked up again.

A collection of constants for job statuses and two functions for workers are in a separate file that should be included in any worker script that processes queue jobs.

## New in Version 1.1

The task column was widened from varchar(255) to text to allow for longer serialized arrays

## New in Version 1.2 (upcoming)

Adding a jobs meta table to be able to assoicate arbitrary key/value pairs to jobs on the queue; this is to make later searching for jobs or debugging easier
