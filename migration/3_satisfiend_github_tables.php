<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @license http://www.horde.org/licenses/lgpl21 LGPL
 */

/**
 * Adds snapshot tables for GitHub issues, pull requests, repositories and
 * releases.
 *
 * Each table stores a point-in-time snapshot of a GitHub object as observed
 * via webhook, REST or GraphQL. The `origin` column is a db-agnostic
 * replacement for a Postgres ENUM: it is a short string constrained to
 * 'webhook', 'rest', 'graphql' or 'other'. Full JSON payloads are stored in
 * `body_json` `text` columns rather than native JSON/JSONB types for
 * portability across backends.
 */
class SatisfiendGithubTables extends Horde_Db_Migration_Base
{
    /**
     * Allowed values of the `origin` column, shared by all tables in this
     * migration.
     */
    private const ORIGIN_VALUES = ['webhook', 'rest', 'graphql', 'other'];

    public function up()
    {
        $tableList = $this->tables();

        if (!in_array('satisfiend_gh_issues', $tableList)) {
            $t = $this->createTable('satisfiend_gh_issues', ['autoincrementKey' => 'snapshot_id']);

            // Provenance
            $t->column('observed_at', 'datetime', ['null' => false]);
            $t->column('origin', 'string', ['limit' => 16, 'null' => false]);
            $t->column('webhook_event', 'string', ['limit' => 64, 'null' => true]);
            $t->column('webhook_action', 'string', ['limit' => 64, 'null' => true]);

            // Global / cross-API identity
            $t->column('node_id', 'string', ['limit' => 255, 'null' => false]);
            $t->column('database_id', 'bigint', ['null' => false]);

            // Repo context
            $t->column('repository_node_id', 'string', ['limit' => 255, 'null' => false]);
            $t->column('repository_owner', 'string', ['limit' => 255, 'null' => false]);
            $t->column('repository_name', 'string', ['limit' => 255, 'null' => false]);
            $t->column('number', 'integer', ['null' => false]);

            // Core state
            $t->column('state', 'string', ['limit' => 16, 'null' => false]);
            $t->column('state_reason', 'string', ['limit' => 32, 'null' => true]);
            $t->column('title', 'text', ['null' => false]);

            // Authorship
            $t->column('author_login', 'string', ['limit' => 255, 'null' => true]);
            $t->column('author_node_id', 'string', ['limit' => 255, 'null' => true]);

            // Classification
            $t->column('assignees', 'text', ['null' => true]);
            $t->column('labels', 'text', ['null' => true]);
            $t->column('milestone_number', 'integer', ['null' => true]);
            $t->column('milestone_node_id', 'string', ['limit' => 255, 'null' => true]);
            $t->column('milestone_title', 'text', ['null' => true]);

            // Flags
            $t->column('is_locked', 'boolean', ['null' => false, 'default' => false]);
            $t->column('is_pinned', 'boolean', ['null' => true]);

            // Counters
            $t->column('comments_count', 'integer', ['null' => true]);
            $t->column('reactions_count', 'integer', ['null' => true]);

            // GitHub timestamps
            $t->column('gh_created_at', 'datetime', ['null' => false]);
            $t->column('gh_updated_at', 'datetime', ['null' => false]);
            $t->column('gh_closed_at', 'datetime', ['null' => true]);

            // Full payload
            $t->column('body_json', 'text', ['null' => false]);

            $t->end();

            $this->addIndex('satisfiend_gh_issues', ['node_id', 'observed_at'], ['name' => 'satisfiend_issue_by_node_id']);
            $this->addIndex('satisfiend_gh_issues', ['repository_owner', 'repository_name', 'number', 'observed_at'], ['name' => 'satisfiend_issue_by_number']);
            $this->addIndex('satisfiend_gh_issues', ['state', 'gh_updated_at'], ['name' => 'satisfiend_issue_by_state']);
            $this->addIndex('satisfiend_gh_issues', ['origin', 'observed_at'], ['name' => 'satisfiend_issue_by_origin']);
        }

        if (!in_array('satisfiend_gh_pull_requests', $tableList)) {
            $t = $this->createTable('satisfiend_gh_pull_requests', ['autoincrementKey' => 'snapshot_id']);

            // Provenance
            $t->column('observed_at', 'datetime', ['null' => false]);
            $t->column('origin', 'string', ['limit' => 16, 'null' => false]);
            $t->column('webhook_event', 'string', ['limit' => 64, 'null' => true]);
            $t->column('webhook_action', 'string', ['limit' => 64, 'null' => true]);

            // Identity
            $t->column('node_id', 'string', ['limit' => 255, 'null' => false]);
            $t->column('database_id', 'bigint', ['null' => false]);
            $t->column('repository_node_id', 'string', ['limit' => 255, 'null' => false]);
            $t->column('repository_owner', 'string', ['limit' => 255, 'null' => false]);
            $t->column('repository_name', 'string', ['limit' => 255, 'null' => false]);
            $t->column('number', 'integer', ['null' => false]);

            // Core state
            $t->column('state', 'string', ['limit' => 16, 'null' => false]);
            $t->column('title', 'text', ['null' => false]);
            $t->column('is_draft', 'boolean', ['null' => false, 'default' => false]);
            $t->column('merged', 'boolean', ['null' => false, 'default' => false]);

            // Review state
            $t->column('mergeable', 'string', ['limit' => 16, 'null' => true]);
            $t->column('review_decision', 'string', ['limit' => 32, 'null' => true]);

            // Authorship
            $t->column('author_login', 'string', ['limit' => 255, 'null' => true]);
            $t->column('author_node_id', 'string', ['limit' => 255, 'null' => true]);
            $t->column('merged_by_login', 'string', ['limit' => 255, 'null' => true]);
            $t->column('merged_by_node_id', 'string', ['limit' => 255, 'null' => true]);

            // Branch / commit refs
            $t->column('head_ref', 'string', ['limit' => 255, 'null' => true]);
            $t->column('head_sha', 'string', ['limit' => 64, 'null' => true]);
            $t->column('base_ref', 'string', ['limit' => 255, 'null' => true]);
            $t->column('base_sha', 'string', ['limit' => 64, 'null' => true]);

            // Classification
            $t->column('assignees', 'text', ['null' => true]);
            $t->column('labels', 'text', ['null' => true]);
            $t->column('requested_reviewers', 'text', ['null' => true]);
            $t->column('milestone_number', 'integer', ['null' => true]);
            $t->column('milestone_node_id', 'string', ['limit' => 255, 'null' => true]);
            $t->column('milestone_title', 'text', ['null' => true]);

            // Flags
            $t->column('is_locked', 'boolean', ['null' => false, 'default' => false]);
            $t->column('maintainer_can_modify', 'boolean', ['null' => true]);

            // Diff stats
            $t->column('commits_count', 'integer', ['null' => true]);
            $t->column('changed_files_count', 'integer', ['null' => true]);
            $t->column('additions', 'integer', ['null' => true]);
            $t->column('deletions', 'integer', ['null' => true]);

            // Counters
            $t->column('comments_count', 'integer', ['null' => true]);
            $t->column('review_comments_count', 'integer', ['null' => true]);
            $t->column('reactions_count', 'integer', ['null' => true]);

            // GitHub timestamps
            $t->column('gh_created_at', 'datetime', ['null' => false]);
            $t->column('gh_updated_at', 'datetime', ['null' => false]);
            $t->column('gh_closed_at', 'datetime', ['null' => true]);
            $t->column('gh_merged_at', 'datetime', ['null' => true]);

            // Full payload
            $t->column('body_json', 'text', ['null' => false]);

            $t->end();

            $this->addIndex('satisfiend_gh_pull_requests', ['node_id', 'observed_at'], ['name' => 'satisfiend_pr_by_node_id']);
            $this->addIndex('satisfiend_gh_pull_requests', ['repository_owner', 'repository_name', 'number', 'observed_at'], ['name' => 'satisfiend_pr_by_number']);
            $this->addIndex('satisfiend_gh_pull_requests', ['state', 'gh_updated_at'], ['name' => 'satisfiend_pr_by_state']);
            $this->addIndex('satisfiend_gh_pull_requests', ['head_sha'], ['name' => 'satisfiend_pr_by_head_sha']);
            $this->addIndex('satisfiend_gh_pull_requests', ['origin', 'observed_at'], ['name' => 'satisfiend_pr_by_origin']);
        }

        if (!in_array('satisfiend_gh_repositories', $tableList)) {
            $t = $this->createTable('satisfiend_gh_repositories', ['autoincrementKey' => 'snapshot_id']);

            // Provenance
            $t->column('github_baseurl', 'string', ['limit' => 255, 'null' => false, 'default' => 'https://github.com']);
            $t->column('observed_at', 'datetime', ['null' => false]);
            $t->column('origin', 'string', ['limit' => 16, 'null' => false]);
            $t->column('webhook_event', 'string', ['limit' => 64, 'null' => true]);
            $t->column('webhook_action', 'string', ['limit' => 64, 'null' => true]);

            // Identity
            $t->column('node_id', 'string', ['limit' => 255, 'null' => false]);
            $t->column('database_id', 'bigint', ['null' => false]);
            $t->column('owner', 'string', ['limit' => 255, 'null' => false]);
            $t->column('name', 'string', ['limit' => 255, 'null' => false]);
            $t->column('full_name', 'string', ['limit' => 511, 'null' => false]);

            // Core state
            $t->column('visibility', 'string', ['limit' => 16, 'null' => false]);
            $t->column('is_private', 'boolean', ['null' => false]);
            $t->column('is_fork', 'boolean', ['null' => false, 'default' => false]);
            $t->column('is_archived', 'boolean', ['null' => false, 'default' => false]);
            $t->column('is_disabled', 'boolean', ['null' => false, 'default' => false]);
            $t->column('is_template', 'boolean', ['null' => false, 'default' => false]);

            // Configuration
            $t->column('default_branch', 'string', ['limit' => 255, 'null' => true]);
            $t->column('has_issues', 'boolean', ['null' => true]);
            $t->column('has_wiki', 'boolean', ['null' => true]);
            $t->column('has_projects', 'boolean', ['null' => true]);
            $t->column('has_discussions', 'boolean', ['null' => true]);
            $t->column('has_pages', 'boolean', ['null' => true]);

            // Content
            $t->column('description', 'text', ['null' => true]);
            $t->column('homepage_url', 'string', ['limit' => 1024, 'null' => true]);
            $t->column('primary_language', 'string', ['limit' => 64, 'null' => true]);
            $t->column('topics', 'text', ['null' => true]);
            $t->column('license_spdx_id', 'string', ['limit' => 64, 'null' => true]);

            // Activity counters
            $t->column('stargazers_count', 'integer', ['null' => true]);
            $t->column('watchers_count', 'integer', ['null' => true]);
            $t->column('forks_count', 'integer', ['null' => true]);
            $t->column('open_issues_count', 'integer', ['null' => true]);
            $t->column('disk_usage_kb', 'integer', ['null' => true]);

            // GitHub timestamps
            $t->column('gh_created_at', 'datetime', ['null' => false]);
            $t->column('gh_updated_at', 'datetime', ['null' => false]);
            $t->column('gh_pushed_at', 'datetime', ['null' => true]);

            // Full payload
            $t->column('body_json', 'text', ['null' => false]);

            $t->end();

            $this->addIndex('satisfiend_gh_repositories', ['node_id', 'observed_at'], ['name' => 'satisfiend_repo_by_node_id']);
            $this->addIndex('satisfiend_gh_repositories', ['full_name', 'observed_at'], ['name' => 'satisfiend_repo_by_full_name']);
            $this->addIndex('satisfiend_gh_repositories', ['origin', 'observed_at'], ['name' => 'satisfiend_repo_by_origin']);
        }

        if (!in_array('satisfiend_github_releases', $tableList)) {
            $t = $this->createTable('satisfiend_github_releases', ['autoincrementKey' => 'snapshot_id']);

            // Provenance
            $t->column('observed_at', 'datetime', ['null' => false]);
            $t->column('origin', 'string', ['limit' => 16, 'null' => false]);
            $t->column('webhook_event', 'string', ['limit' => 64, 'null' => true]);
            $t->column('webhook_action', 'string', ['limit' => 64, 'null' => true]);

            // Identity
            $t->column('node_id', 'string', ['limit' => 255, 'null' => false]);
            $t->column('database_id', 'bigint', ['null' => false]);
            $t->column('repository_node_id', 'string', ['limit' => 255, 'null' => false]);
            $t->column('repository_owner', 'string', ['limit' => 255, 'null' => false]);
            $t->column('repository_name', 'string', ['limit' => 255, 'null' => false]);
            $t->column('tag_name', 'string', ['limit' => 255, 'null' => false]);

            // Core state
            $t->column('name', 'string', ['limit' => 255, 'null' => true]);
            $t->column('is_draft', 'boolean', ['null' => false, 'default' => false]);
            $t->column('is_prerelease', 'boolean', ['null' => false, 'default' => false]);
            $t->column('is_latest', 'boolean', ['null' => true]);

            // Target
            $t->column('target_commitish', 'string', ['limit' => 255, 'null' => true]);

            // Authorship
            $t->column('author_login', 'string', ['limit' => 255, 'null' => true]);
            $t->column('author_node_id', 'string', ['limit' => 255, 'null' => true]);

            // Assets summary
            $t->column('assets_count', 'integer', ['null' => true]);
            $t->column('download_count', 'integer', ['null' => true]);

            // GitHub timestamps
            $t->column('gh_created_at', 'datetime', ['null' => false]);
            $t->column('gh_published_at', 'datetime', ['null' => true]);

            // Full payload
            $t->column('body_json', 'text', ['null' => false]);

            $t->end();

            $this->addIndex('satisfiend_github_releases', ['node_id', 'observed_at'], ['name' => 'satisfiend_release_by_node_id']);
            $this->addIndex('satisfiend_github_releases', ['repository_owner', 'repository_name', 'tag_name', 'observed_at'], ['name' => 'satisfiend_release_by_tag']);
            $this->addIndex('satisfiend_github_releases', ['origin', 'observed_at'], ['name' => 'satisfiend_release_by_origin']);
        }
    }

    public function down()
    {
        $this->dropTable('satisfiend_github_releases');
        $this->dropTable('satisfiend_gh_repositories');
        $this->dropTable('satisfiend_gh_pull_requests');
        $this->dropTable('satisfiend_gh_issues');
    }
}
