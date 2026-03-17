## Configurable Settings System

### Role-Based Proctor Assignment

The Proctor Roles feature allows for customizable assignments of proctors based on specific roles. This capability enhances the flexibility of the proctoring process. To facilitate this, a new "Role Settings" tab has been introduced, which consolidates the following settings:

- **Shortcode Visibility**: Control who can see the applied shortcodes.
- **Proctor Role Restrictions**: Set restrictions based on assigned roles to ensure that only the eligible proctors can access certain functionalities.

#### get_proctor_roles() Method

The `get_proctor_roles()` method is designed to retrieve the list of available proctor roles within the system. When no roles are selected, the method defaults to assigning all available roles to the user, ensuring that proctors have the necessary access until specific assignments are made. This behavior maintains a seamless experience across the proctoring functionality.
