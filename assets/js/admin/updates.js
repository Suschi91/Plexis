$().ready(function() {
    var width = $(".grid_8").width();
    width = (width * .75);
    
    // Init the progressbar
    myProgressBar = new ProgressBar("progressbar", {
        borderRadius: 5,
        width: width,
        height: 20,
        value: 0,
        maxValue: 100,
        labelText: "{progress}%",
        orientation: ProgressBar.Orientation.Horizontal,
        direction: ProgressBar.Direction.LeftToRight,
        animationStyle: ProgressBar.AnimationStyle.LeftToRight1,
        animationSpeed: 1.5,
        imageUrl: Plexis.template_url + "/img/misc/progressbar/fg.png",
        backgroundUrl: Plexis.template_url + "/img/misc/progressbar/bg.png"
    });

    $("#details").delegate('a#update-cms', 'click', function(){
        process();
    });
});

// Function used to process the update
function process()
{
    $.ajax({
        type: "POST",
        url: Plexis.url + "/admin_ajax/update",
        data: {action: "next", sha: update_sha},
        dataType: "json",
        timeout: 5000, // in milliseconds
        success: function(data) 
        {
            // Make sure we have the update!
            if(data.success == false)
            {
                throw 'Error: ' + data.data;
                return false;
            }
            else
            {
                result = data['data'];
            }

            // For progress bar!
            var count = result.length;
            var add = (100 / count);
            var running = 0;
            
            // Globals
            var update_error = false;
            var update_message = '';
            
            // show progress bar
            $('#update').show();
            $('#details').hide();
            
            // Set the site up for maintenace
            $.ajax({
                type: "POST",
                url: Plexis.url + '/admin_ajax/update',
                data: { action: 'init' },
                dataType: "json",
                timeout: 5000, // in milliseconds
                success: function(result) {}
            });
            
            // Get the current update rev number
            $.each(result, function(key, value){
                // Update the update status texts
                current = (key+1);
                tmp = parseFloat(running);
                s = value['status'];
                status = s.substr(0, 1);
                
                // Get our file mode
                switch(status)
                {
                    case "M":
                        mode = 'Updating';
                        break;
                    case "A":
                        mode = 'Adding';
                        break;
                    case "D":
                        mode = 'Removing';
                        break;
                    case "R":
                        mode = 'Renaming';
                        break;
                    default:
                        mode = '';
                        break;
                }
                
                // Update the status
                $('#update-state').html('<center>' + mode + ' file: "' + value['file'] +'"</center>');
  
                // Send action
                $.ajax({
                    type: "POST",
                    url: Plexis.url + '/admin_ajax/update',
                    data: { 
                        action: "update", 
                        status: value['status'], 
                        sha: update_sha, 
                        filename: value['file'],
                    },
                    dataType: "json",
                    async: false,
                    timeout: 15000, // in milliseconds
                    success: function(result) 
                    {
                        if(typeof result.php_error != "undefined" && result.php_error == true)
                        {
                            data = result.php_error_data;
                            update_error = true;
                            update_message = data.message;
                            
                            $.msgbox('Update failed because a PHP error was encountered!<br /><br >  Message: '+ data.message +'<br /> File: '+ data.file +'<br /> Line: '+ data.line, {
                                type : 'error'
                            });
                        }
                        else
                        {
                            if(result.success == true)
                            {
                                // Progress
                                running += add;
                                myProgressBar.setValue( running );
                            }
                            else
                            {
                                update_error = true;
                                update_message = result.message;
                            }
                        }
                        
                    },
                    error: function(request, status, err) 
                    {
                        // Show that there we cant connect to the update server
                        update_error = true;
                        show_ajax_error(status);
                    }
                });
                
                // Stop the loop!
                if(update_error == true) return false;
            });
            
            // Set the site up for maintenace to false
            $.ajax({
                type: "POST",
                url: Plexis.url + '/admin_ajax/update',
                data: { action: 'finish' },
                dataType: "json",
                timeout: 5000, // in milliseconds
                success: function(result) {}
            });
            
            // Process out message back to the user based on if we had errors
            if(update_error == false)
            {
                $('#update').fadeOut(300, function(){
                    $('#update-finished').fadeIn(300);
                });
            }
            else
            {
                $('#update').fadeOut(300, function(){
                    $('#update-finished').html('<p><div class="alert error">'+ update_message +'</div></p>').fadeIn(300);
                });
            }
        },
        error: function(request, status, err) 
        {
            // Show that there we cant connect to the update server
            $('#js_message').html('<font color="orange">Unable to connect to update server.</font>');
        }
    });
}