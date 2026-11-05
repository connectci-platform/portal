(function($) {
  "use strict"; // Start of use strict
  var $bs = window.jQueryBootstrap || $; // Legacy jQuery instance that Bootstrap 4 is bound to.

  $(function () {
    $bs('[data-toggle="tooltip"]').tooltip();
    $bs('[data-toggle="popover"]').popover();
  });

  // Floating label headings for the contact form
  $("body").on("input propertychange", ".floating-label-form-group", function(e) {
    $(this).toggleClass("floating-label-form-group-with-value", !!$(e.target).val());
  }).on("focus", ".floating-label-form-group", function() {
    $(this).addClass("floating-label-form-group-with-focus");
  }).on("blur", ".floating-label-form-group", function() {
    $(this).removeClass("floating-label-form-group-with-focus");
  });

  // Show the navbar when the page is scrolled up
  var MQL = 992;

  $(document).scroll(function () {
	var doc = $(this).height();
	var win = $(window).height();
	if ( doc > win ) {
		var $body = $("body");
		var $nav = $("#mainNav");
		var $secNav = $("#secondaryNav");
		var $combined = $nav.height() + $secNav.height();
		//$nav.toggleClass('scrolled shadow-sm', $(this).scrollTop() > $combined);
		//$secNav.toggleClass('scrolled', $(this).scrollTop() > $combined);
	}
  });
  //primary navigation slide-in effect
  /*if ($(window).width() > MQL) {
    var headerHeight = $('#mainNav').height();
    $(window).on('scroll', {
        previousTop: 0
      },
      function() {
        var currentTop = $(window).scrollTop();
        //check if user is scrolling up
        if (currentTop < this.previousTop) {
          //if scrolling up...
          if (currentTop > 0 && $('#mainNav').hasClass('is-fixed')) {
            $('#mainNav').addClass('is-visible');
          } else {
            $('#mainNav').removeClass('is-visible is-fixed');
          }
        } else if (currentTop > this.previousTop) {
          //if scrolling down...
          $('#mainNav').removeClass('is-visible');
          if (currentTop > headerHeight && !$('#mainNav').hasClass('is-fixed')) $('#mainNav').addClass('is-fixed');
        }
        this.previousTop = currentTop;
      });
  }*/

$(document).ready(function() {
  $bs("#projectSlider").on("slide.bs.carousel", function(e) {
    var $e = $(e.relatedTarget);
    var idx = $e.index();
    var itemsPerSlide = 3;
    var totalItems = $(".carousel-item").length;

    if (idx >= totalItems - (itemsPerSlide - 1)) {
      var it = itemsPerSlide - (totalItems - idx);
      for (var i = 0; i < it; i++) {
        // append slides to end
        if (e.direction == "left") {
          $(".carousel-item").eq(i).appendTo($(this).find(".carousel-inner"));
        } else {
          $(".carousel-item").eq(0).appendTo($(this).find(".carousel-inner"));
        }
      }
    }
  });
  $bs("#studentSlider").on("slide.bs.carousel", function(e) {
    var $e = $(e.relatedTarget);
    var idx = $e.index();
    var itemsPerSlide = 5;
    var totalItems = $(".carousel-item").length;

    if (idx >= totalItems - (itemsPerSlide - 1)) {
      var it = itemsPerSlide - (totalItems - idx);
      for (var i = 0; i < it; i++) {
        // append slides to end
        if (e.direction == "left") {
          $(".carousel-item").eq(i).appendTo('.carousel-inner');
        } else {
          $(".carousel-item").eq(0).appendTo('.carousel-inner');
        }
      }
    }
  });
  $bs("#mentorSlider").on("slide.bs.carousel", function(e) {
    var $e = $(e.relatedTarget);
    var idx = $e.index();
    var itemsPerSlide = 3;
    var totalItems = $(".carousel-item").length;

    if (idx >= totalItems - (itemsPerSlide - 1)) {
      var it = itemsPerSlide - (totalItems - idx);
      for (var i = 0; i < it; i++) {
        // append slides to end
        if (e.direction == "left") {
          $(".carousel-item").eq(i).appendTo($(this).find(".carousel-inner"));
        } else {
          $(".carousel-item").eq(0).appendTo($(this).find(".carousel-inner"));
        }
      }
    }
  });
  $bs("#researcherSlider").on("slide.bs.carousel", function(e) {
    var $e = $(e.relatedTarget);
    var idx = $e.index();
    var itemsPerSlide = 3;
    var totalItems = $(".carousel-item").length;

    if (idx >= totalItems - (itemsPerSlide - 1)) {
      var it = itemsPerSlide - (totalItems - idx);
      for (var i = 0; i < it; i++) {
        // append slides to end
        if (e.direction == "left") {
          $(".carousel-item").eq(i).appendTo(".carousel-inner");
        } else {
          $(".carousel-item").eq(0).appendTo($(this).find(".carousel-inner"));
        }
      }
    }
  });
});




})(jQuery); // End of use strict
